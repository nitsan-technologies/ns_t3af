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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiServiceInterface;

/**
 * Turns raw MCP tool payloads into editor-facing agent answers (A–D).
 *
 * @internal
 */
final readonly class AgentToolResultPresenter
{
    private const IMPORTANT_KEYS = [
        'title',
        'header',
        'name',
        'uid',
        'pid',
        'slug',
        'doktype',
        'hidden',
        'deleted',
        'description',
        'subtitle',
        'error',
        'message',
        'count',
        'total',
    ];

    public function __construct(
        private AiServiceInterface $aiService,
    ) {}

    /**
     * @return array{
     *     content: string,
     *     success: bool,
     *     summary: string,
     *     llmSummary: string|null,
     *     facts: list<array{label: string, value: string}>,
     *     details: mixed,
     *     error: string|null
     * }
     */
    public function present(
        string $toolName,
        mixed $rawResult,
        bool $invokeSuccess,
        string $invokeMessage = '',
        ?int $pageId = null,
    ): array {
        $details = $this->normalizePayload($rawResult);
        $error = $this->resolveError($details, $invokeSuccess, $invokeMessage);
        $facts = $error === null ? $this->buildFacts($toolName, $details) : [];
        $summary = $error !== null
            ? $error
            : $this->buildDeterministicSummary($toolName, $facts, $details);

        $llmSummary = null;
        if ($error === null && !$this->shouldSkipLlmSummary($facts)) {
            $llmSummary = $this->tryLlmSummary($toolName, $details, $pageId);
        }

        $listLead = $this->buildListLeadSummary($facts);

        return [
            'content' => $llmSummary ?? $listLead ?? $summary,
            'success' => $error === null,
            'summary' => $summary,
            'llmSummary' => $llmSummary,
            'facts' => $facts,
            'details' => $details,
            'error' => $error,
        ];
    }

    private function normalizePayload(mixed $raw): mixed
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '' || strtolower($trimmed) === 'null') {
                return null;
            }
            try {
                return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $trimmed;
            }
        }

        return $raw;
    }

    private function resolveError(mixed $details, bool $invokeSuccess, string $invokeMessage): ?string
    {
        if (!$invokeSuccess) {
            $message = trim($invokeMessage);

            return $message !== '' ? $message : 'The tool failed.';
        }

        if (is_array($details) && isset($details['error']) && is_scalar($details['error'])) {
            $error = trim((string) $details['error']);

            return $error !== '' ? $error : 'The tool reported an error.';
        }

        if ($details === null) {
            return 'The tool returned no data.';
        }

        return null;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function buildFacts(string $toolName, mixed $details): array
    {
        if (!is_array($details)) {
            if (is_scalar($details)) {
                return [['label' => 'Result', 'value' => (string) $details]];
            }

            return [];
        }

        if ($this->isList($details)) {
            return $this->factsForList($toolName, array_values($details));
        }

        return match (true) {
            str_starts_with($toolName, 'pages_') => $this->factsForPage($details),
            str_starts_with($toolName, 'content_') => $this->factsForContent($details),
            default => $this->factsGeneric($details),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{label: string, value: string}>
     */
    private function factsForPage(array $row): array
    {
        $facts = [];
        $this->pushFact($facts, 'Title', $row['title'] ?? null);
        $this->pushFact($facts, 'UID', $row['uid'] ?? null);
        $this->pushFact($facts, 'Parent', $row['pid'] ?? null);
        $this->pushFact($facts, 'Slug', $row['slug'] ?? null);
        if (isset($row['doktype'])) {
            $this->pushFact($facts, 'Type', $this->doktypeLabel((int) $row['doktype']));
        }
        if (isset($row['hidden'])) {
            $this->pushFact($facts, 'Visibility', ((int) $row['hidden'] === 1) ? 'Hidden' : 'Visible');
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{label: string, value: string}>
     */
    private function factsForContent(array $row): array
    {
        $facts = [];
        $header = trim((string) ($row['header'] ?? ''));
        $this->pushFact($facts, 'Header', $header !== '' ? $header : null);
        $this->pushFact($facts, 'UID', $row['uid'] ?? null);
        $this->pushFact($facts, 'Page', $row['pid'] ?? null);
        $this->pushFact($facts, 'Type', $row['CType'] ?? ($row['ctype'] ?? null));

        return $facts;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array{label: string, value: string}>
     */
    private function factsForList(string $toolName, array $rows): array
    {
        $facts = [
            ['label' => 'Items', 'value' => (string) count($rows)],
        ];

        $labels = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['title'] ?? $row['header'] ?? $row['name'] ?? ''));
            $uid = isset($row['uid']) ? (string) (int) $row['uid'] : '';
            if ($label === '' && $uid === '') {
                continue;
            }
            $labels[] = $label !== '' ? ($uid !== '' ? $label . ' [' . $uid . ']' : $label) : ('#' . $uid);
        }
        if ($labels !== []) {
            $facts[] = ['label' => 'Includes', 'value' => implode(', ', $labels)];
        }

        if ($toolName !== '') {
            // Keep tool name out of facts; summary names it.
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{label: string, value: string}>
     */
    private function factsGeneric(array $row): array
    {
        $facts = [];
        foreach (self::IMPORTANT_KEYS as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $this->pushFact($facts, $this->humanizeKey($key), $row[$key]);
            if (count($facts) >= 6) {
                break;
            }
        }

        return $facts;
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function buildDeterministicSummary(string $toolName, array $facts, mixed $details): string
    {
        if ($facts === []) {
            if (is_scalar($details)) {
                return (string) $details;
            }

            return sprintf('Tool “%s” completed successfully.', $toolName);
        }

        $titleFact = null;
        foreach ($facts as $fact) {
            if (in_array($fact['label'], ['Title', 'Header', 'Name'], true)) {
                $titleFact = $fact['value'];
                break;
            }
        }

        if ($titleFact !== null) {
            $uid = $this->factValue($facts, 'UID');
            $line = $titleFact;
            if ($uid !== null) {
                $line .= ' · uid ' . $uid;
            }
            $extras = [];
            foreach ($facts as $fact) {
                if (in_array($fact['label'], ['Title', 'Header', 'Name', 'UID'], true)) {
                    continue;
                }
                $extras[] = $fact['label'] . ': ' . $fact['value'];
            }
            if ($extras !== []) {
                $line .= "\n" . implode(' · ', array_slice($extras, 0, 4));
            }

            return $line;
        }

        $parts = [];
        foreach (array_slice($facts, 0, 5) as $fact) {
            $parts[] = $fact['label'] . ': ' . $fact['value'];
        }

        return implode("\n", $parts);
    }

    private function tryLlmSummary(string $toolName, mixed $details, ?int $pageId): ?string
    {
        try {
            $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (strlen($encoded) > 6000) {
            $encoded = substr($encoded, 0, 6000) . '…';
        }

        $prompt = implode("\n", [
            'You are the TYPO3 AI Agent speaking to a backend editor.',
            'Summarize the following tool result in 2-4 short sentences.',
            'Highlight the important fields (title, uid, status, errors).',
            'Do not invent data. Do not use markdown — no asterisks, bullet lists, or headings. Plain sentences only.',
            'Tool name: ' . $toolName,
            'Result JSON:',
            $encoded,
        ]);

        try {
            $response = $this->aiService->complete($prompt, new AiOptions(
                temperature: 0.2,
                maxTokens: 220,
                extensionKey: 'ns_t3af',
                featureKey: 'agent.tool_summary',
                featureLabel: 'AI Agent tool summary',
                requestSource: 'agent',
                pageId: $pageId,
                extra: [
                    'skipBrandContext' => true,
                ],
            ));
        } catch (\Throwable) {
            return null;
        }

        $text = trim($response->content);

        return $text !== '' ? $text : null;
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function shouldSkipLlmSummary(array $facts): bool
    {
        return $this->hasFact($facts, 'Items') && $this->hasFact($facts, 'Includes');
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function buildListLeadSummary(array $facts): ?string
    {
        $items = $this->factValue($facts, 'Items');
        if ($items === null) {
            return null;
        }

        $count = (int) $items;
        if ($count <= 0) {
            return 'No items found.';
        }

        if ($count === 1) {
            return 'Found 1 item — details below.';
        }

        return sprintf('Found %d items — details below.', $count);
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function hasFact(array $facts, string $label): bool
    {
        foreach ($facts as $fact) {
            if ($fact['label'] === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function pushFact(array &$facts, string $label, mixed $value): void
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return;
        }
        $string = trim((string) $value);
        if ($string === '') {
            return;
        }
        $facts[] = ['label' => $label, 'value' => $string];
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function factValue(array $facts, string $label): ?string
    {
        foreach ($facts as $fact) {
            if ($fact['label'] === $label) {
                return $fact['value'];
            }
        }

        return null;
    }

    private function humanizeKey(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private function doktypeLabel(int $doktype): string
    {
        return match ($doktype) {
            1 => 'Standard page',
            3 => 'Link to external URL',
            4 => 'Shortcut',
            6 => 'Backend user section',
            7 => 'Mount point',
            199 => 'Menu separator',
            254 => 'Folder',
            255 => 'Recycler',
            default => 'Doktype ' . $doktype,
        };
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
