<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, either version 2 of the
 * License, or (at your option) any later version.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Agent\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Deterministic read fast-paths for NL turns (no LLM required).
 *
 * @internal
 */
final readonly class AgentReadFastPathService
{
    /** @var list<string> */
    private const SEO_FIELD_KEYS = [
        'description',
        'abstract',
        'keywords',
        'seo_title',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
    ];

    public function __construct(
        private PermittedActionProvider $permittedActionProvider,
        private AgentToolTurnProcessor $toolTurnProcessor,
        private AgentFieldExtractor $fieldExtractor,
        private AgentNlIntentResolver $nlIntentResolver,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    public function resolve(
        string $userMessage,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        if ($this->isFileModule($context) || $this->nlIntentResolver->looksLikeFileAssetQuery($userMessage)) {
            return [];
        }

        $pageId = (int) ($context['pageId'] ?? 0);
        if ($pageId <= 0) {
            return [];
        }

        $catalog = $this->permittedActionProvider->buildCatalog();

        $contentReply = $this->tryPageContentRead($userMessage, $pageId, $context, $body, $user, $correlationId, $catalog);
        if ($contentReply !== null) {
            return [$contentReply];
        }

        $seoReply = $this->trySeoMetadataRead($userMessage, $pageId, $context, $body, $user, $correlationId, $catalog);
        if ($seoReply !== null) {
            return [$seoReply];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function trySeoMetadataRead(
        string $userMessage,
        int $pageId,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $catalog,
    ): ?array {
        if (!$this->nlIntentResolver->isSeoMetadataReadQuery($userMessage)) {
            return null;
        }
        if (!$this->toolExistsInCatalog($catalog, 'pages_get')) {
            return null;
        }

        $toolBody = $body;
        $toolBody['arguments'] = ['uid' => $pageId];
        $toolMessage = $this->toolTurnProcessor->execute('pages_get', $context, $toolBody, $user, $correlationId);
        $raw = $toolMessage['meta']['rawResult'] ?? null;
        $record = $this->normalizeSingleRecord($raw);
        if ($record === []) {
            $fallback = trim((string) ($toolMessage['content'] ?? ''));

            return $fallback !== '' ? $this->nlReply($fallback, $toolMessage, $correlationId, 'pages_get') : null;
        }

        $lines = ['SEO-related fields on this page (' . $pageId . '):'];
        foreach (self::SEO_FIELD_KEYS as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }
            $value = trim(strip_tags((string) $record[$key]));
            if ($value === '') {
                $lines[] = '- ' . $key . ': (empty)';
                continue;
            }
            if (strlen($value) > 200) {
                $value = substr($value, 0, 197) . '…';
            }
            $lines[] = '- ' . $key . ': ' . $value;
        }

        $title = trim((string) ($record['title'] ?? ''));
        if ($title !== '') {
            array_splice($lines, 1, 0, ['- title: ' . $title]);
        }

        $content = implode("\n", $lines);
        $extracted = $this->fieldExtractor->extract($userMessage, 'pages_get', $raw);

        return $this->nlReply($content, $toolMessage, $correlationId, 'pages_get', $extracted['facts']);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function tryPageContentRead(
        string $userMessage,
        int $pageId,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $catalog,
    ): ?array {
        if (!$this->nlIntentResolver->looksLikePageContentQuery($userMessage)) {
            return null;
        }

        $needles = $this->nlIntentResolver->extractContentSearchNeedles($userMessage);
        if ($needles === []) {
            return null;
        }

        if (!$this->toolExistsInCatalog($catalog, 'content_list')) {
            return null;
        }

        $toolBody = $body;
        $toolBody['arguments'] = [
            'pid' => $pageId,
            'limit' => 100,
            'selectFields' => 'uid,pid,header,subheader,bodytext,CType',
        ];
        $toolMessage = $this->toolTurnProcessor->execute('content_list', $context, $toolBody, $user, $correlationId);
        $records = $this->normalizeContentRecords($toolMessage['meta']['rawResult'] ?? null);
        $matches = $this->filterContentRecordsByNeedles($records, $needles);

        if ($matches === [] && $this->toolExistsInCatalog($catalog, 'content_search')) {
            foreach ($needles as $needle) {
                $searchBody = $body;
                $searchBody['arguments'] = [
                    'search' => $needle,
                    'pid' => $pageId,
                    'limit' => 10,
                ];
                $searchMessage = $this->toolTurnProcessor->execute(
                    'content_search',
                    $context,
                    $searchBody,
                    $user,
                    $correlationId,
                );
                $searchRecords = $this->normalizeContentRecords($searchMessage['meta']['rawResult'] ?? null);
                if ($searchRecords !== []) {
                    $matches = $searchRecords;
                    $toolMessage = $searchMessage;
                    break;
                }
            }
        }

        if ($matches === []) {
            if ($records === []) {
                return null;
            }

            $content = $this->translate(
                'agent.turn.noContentMatch',
                [
                    implode(', ', $needles),
                    "\n\n",
                    $this->formatContentMatchSummary(array_slice($records, 0, 8), preview: true),
                ],
            );

            return $this->nlReply($content, $toolMessage, $correlationId, 'content_list');
        }

        if (count($matches) === 1 && $this->toolExistsInCatalog($catalog, 'content_get')) {
            $uid = (int) ($matches[0]['uid'] ?? 0);
            if ($uid > 0) {
                $getBody = $body;
                $getBody['arguments'] = ['uid' => $uid];
                $getMessage = $this->toolTurnProcessor->execute(
                    'content_get',
                    $context,
                    $getBody,
                    $user,
                    $correlationId,
                );
                $fullRecord = $this->normalizeSingleRecord($getMessage['meta']['rawResult'] ?? null);
                if ($fullRecord !== []) {
                    return $this->nlReply(
                        $this->formatContentDetail($fullRecord),
                        $getMessage,
                        $correlationId,
                        'content_get',
                    );
                }
            }
        }

        return $this->nlReply(
            $this->formatContentMatchSummary($matches),
            $toolMessage,
            $correlationId,
            'content_list',
        );
    }

    /**
     * @param array{role: string, content: string, meta: array<string, mixed>} $toolMessage
     * @param list<array{label: string, value: string}> $facts
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function nlReply(
        string $content,
        array $toolMessage,
        string $correlationId,
        string $prefetch,
        array $facts = [],
    ): array {
        return [
            'role' => 'assistant',
            'content' => $content,
            'meta' => array_merge(
                is_array($toolMessage['meta']) ? $toolMessage['meta'] : [],
                [
                    'type' => 'nl_reply',
                    'correlationId' => $correlationId,
                    'prefetch' => $prefetch,
                    'facts' => $facts,
                ],
            ),
        ];
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     */
    private function toolExistsInCatalog(array $catalog, string $toolName): bool
    {
        $needle = strtolower(trim($toolName));
        foreach ($catalog['executable'] as $tool) {
            if (strtolower((string) ($tool['name'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSingleRecord(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return [];
        }
        if (isset($payload['record']) && is_array($payload['record'])) {
            return $payload['record'];
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeContentRecords(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return [];
        }
        if (isset($payload['records']) && is_array($payload['records'])) {
            /** @var list<array<string, mixed>> $records */
            $records = array_values(array_filter($payload['records'], 'is_array'));

            return $records;
        }
        if (isset($payload[0]) && is_array($payload[0])) {
            /** @var list<array<string, mixed>> $records */
            $records = array_values(array_filter($payload, 'is_array'));

            return $records;
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param list<string> $needles
     * @return list<array<string, mixed>>
     */
    private function filterContentRecordsByNeedles(array $records, array $needles): array
    {
        if ($records === [] || $needles === []) {
            return [];
        }

        $matches = [];
        foreach ($records as $record) {
            $haystack = strtolower(
                trim((string) ($record['header'] ?? ''))
                . ' '
                . trim((string) ($record['subheader'] ?? ''))
                . ' '
                . $this->htmlToPlainText((string) ($record['bodytext'] ?? '')),
            );
            if ($haystack === '') {
                continue;
            }
            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    $matches[] = $record;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function formatContentMatchSummary(array $records, bool $preview = false): string
    {
        $lines = [];
        foreach (array_slice($records, 0, 5) as $record) {
            $lines[] = $this->formatContentDetail($record, $preview);
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function formatContentDetail(array $record, bool $preview = false): string
    {
        $uid = (int) ($record['uid'] ?? 0);
        $header = trim((string) ($record['header'] ?? ''));
        $label = $header !== '' ? $header : '(no header)';
        $parts = [sprintf('tt_content:%d — %s', $uid, $label)];

        $subheader = $this->htmlToPlainText((string) ($record['subheader'] ?? ''));
        if ($subheader !== '') {
            $parts[] = $subheader;
        }

        $body = $this->htmlToPlainText((string) ($record['bodytext'] ?? ''));
        if ($body !== '') {
            if ($preview) {
                // ponytail: list previews only; full detail uses content_get (no ceiling)
                $max = 400;
                if (strlen($body) > $max) {
                    $body = substr($body, 0, $max - 1) . '…';
                }
            }
            $parts[] = $body;
        }

        return implode("\n\n", $parts);
    }

    private function htmlToPlainText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<(br|BR)\s*\/?>/', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|td|th|blockquote)>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<(p|div|h[1-6]|li|tr|td|th|blockquote)(\s[^>]*)?>/i', '', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isFileModule(array $context): bool
    {
        $module = strtolower(trim((string) ($context['module'] ?? '')));

        return str_starts_with($module, 'file') || $module === 'media_management';
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        $value = $languageService->sL('LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key);
        if ($arguments === []) {
            return $value;
        }

        return sprintf($value, ...array_map(static fn(int|string $argument): string => (string) $argument, $arguments));
    }
}
