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

    /**
     * Facts carry a stable key for lookups and a translated label for the editor.
     *
     * @var array<string, string>
     */
    private const FACT_LABEL_KEYS = [
        'result' => 'agent.fact.result',
        'file' => 'agent.fact.file',
        'files' => 'agent.fact.files',
        'folders' => 'agent.fact.folders',
        'examples' => 'agent.fact.examples',
        'includes' => 'agent.fact.includes',
        'items' => 'agent.fact.items',
        'missingAltText' => 'agent.fact.missingAltText',
        'matchingFiles' => 'agent.fact.matchingFiles',
        'matchingPages' => 'agent.fact.matchingPages',
        'matchingContentElements' => 'agent.fact.matchingContentElements',
        'matchingRecords' => 'agent.fact.matchingRecords',
        'pages' => 'agent.fact.pages',
        'contentElements' => 'agent.fact.contentElements',
        'redirects' => 'agent.fact.redirects',
        'scheduledTasks' => 'agent.fact.scheduledTasks',
        'records' => 'agent.fact.records',
        'altText' => 'agent.fact.altText',
        'title' => 'agent.fact.title',
        'header' => 'agent.fact.header',
        'name' => 'agent.fact.name',
        'uid' => 'agent.fact.uid',
        'pid' => 'agent.fact.parent',
        'page' => 'agent.fact.page',
        'slug' => 'agent.fact.slug',
        'doktype' => 'agent.fact.type',
        'hidden' => 'agent.fact.visibility',
        'deleted' => 'agent.fact.deleted',
        'description' => 'agent.fact.description',
        'subtitle' => 'agent.fact.subtitle',
        'error' => 'agent.fact.error',
        'message' => 'agent.fact.message',
        'count' => 'agent.fact.count',
        'total' => 'agent.fact.total',
    ];

    /** Fact keys whose value is a collection size. */
    private const COUNT_FACT_KEYS = [
        'missingAltText',
        'items',
        'files',
        'matchingFiles',
        'matchingPages',
        'matchingContentElements',
        'matchingRecords',
        'pages',
        'contentElements',
        'redirects',
        'scheduledTasks',
        'records',
    ];

    public function __construct(
        private AiServiceInterface $aiService,
        private AgentToolEditorLabelService $editorLabelService,
        private AgentLanguageResolver $languageResolver,
        private AgentTranslator $translator,
    ) {}

    /**
     * @return array{
     *     content: string,
     *     success: bool,
     *     summary: string,
     *     llmSummary: string|null,
     *     facts: list<array{key: string, label: string, value: string}>,
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
        bool $allowLlmSummary = true,
    ): array {
        $details = $this->normalizePayload($rawResult);
        $error = $this->resolveError($details, $invokeSuccess, $invokeMessage);
        $editorLabel = $this->editorLabelService->resolveByName($toolName);
        $facts = $error === null ? $this->buildFacts($toolName, $details) : [];
        $summary = $error !== null
            ? $error
            : $this->buildDeterministicSummary($editorLabel, $facts, $details);

        $llmSummary = null;
        $hasReadySummary = is_array($details)
            && isset($details['summary'])
            && is_string($details['summary'])
            && trim($details['summary']) !== '';
        if ($allowLlmSummary && $error === null && !$hasReadySummary && !$this->shouldSkipLlmSummary($facts)) {
            $llmSummary = $this->tryLlmSummary($editorLabel, $details, $pageId);
        }

        $listLead = $this->buildListLeadSummary($toolName, $facts, $details);
        $toolLead = $this->buildToolSpecificContent($toolName, $facts, $details);

        return [
            'content' => $llmSummary ?? $toolLead ?? $listLead ?? $summary,
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

            return $message !== '' ? $message : $this->translator->translate('agent.result.toolFailed');
        }

        if (is_array($details) && isset($details['error']) && is_scalar($details['error'])) {
            $error = trim((string) $details['error']);

            return $error !== '' ? $error : $this->translator->translate('agent.result.toolError');
        }

        if ($details === null) {
            return $this->translator->translate('agent.result.toolNoData');
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string, value: string}>
     */
    private function buildFacts(string $toolName, mixed $details): array
    {
        if (!is_array($details)) {
            $facts = [];
            $this->pushFact($facts, 'result', $details);

            return $facts;
        }

        if ($toolName === 'file_list') {
            return $this->factsForFileDirectoryListing($details);
        }

        if ($toolName === 'file_get_info' || str_starts_with($toolName, 't3aa_get_file')) {
            return $this->factsForFileMetadata($details);
        }

        $collection = $this->unwrapCollectionPayload($details);
        if ($collection !== null) {
            return $this->factsForCollection($toolName, $collection);
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
     * @param array<string, mixed> $details
     * @return list<array{key: string, label: string, value: string}>
     */
    private function factsForFileDirectoryListing(array $details): array
    {
        $files = is_array($details['files'] ?? null) ? $details['files'] : [];
        $directories = is_array($details['directories'] ?? null) ? $details['directories'] : [];
        $fileCount = (int) ($details['totalFiles'] ?? count($files));
        $folderCount = (int) ($details['totalDirectories'] ?? count($directories));

        $facts = [
            $this->fact('files', (string) $fileCount),
            $this->fact('folders', (string) $folderCount),
        ];

        /** @var list<array<string, mixed>> $fileRows */
        $fileRows = array_values(array_filter($files, 'is_array'));
        $examples = $this->extractRowLabels($fileRows);

        return $examples === [] ? $facts : array_merge($facts, [$this->fact('examples', implode(', ', $examples))]);
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, kind: string} $collection
     * @return list<array{key: string, label: string, value: string}>
     */
    private function factsForCollection(string $toolName, array $collection): array
    {
        $total = max(0, (int) ($collection['total'] ?? count($collection['rows'])));
        $rows = $collection['rows'];
        $countKey = match ($toolName) {
            't3aa_list_files_missing_alt_text' => 'missingAltText',
            'file_search' => 'matchingFiles',
            'pages_search' => 'matchingPages',
            'content_search' => 'matchingContentElements',
            'record_search' => 'matchingRecords',
            'pages_list' => 'pages',
            'content_list' => 'contentElements',
            'redirect_list' => 'redirects',
            'scheduler_list' => 'scheduledTasks',
            default => $collection['kind'] === 'files' ? 'files' : 'items',
        };

        $facts = [$this->fact($countKey, (string) $total)];
        $examples = $this->extractRowLabels($rows);
        if ($examples !== []) {
            $facts[] = $this->fact('examples', implode(', ', $examples));
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $details
     * @return array{rows: list<array<string, mixed>>, total: int, kind: string}|null
     */
    private function unwrapCollectionPayload(array $details): ?array
    {
        $candidates = [
            'items' => ['total', 'count'],
            'records' => ['total', 'count'],
            'results' => ['total', 'count'],
            'rows' => ['total', 'count'],
            'files' => ['totalFiles', 'total', 'count'],
        ];

        foreach ($candidates as $listKey => $totalKeys) {
            if (!isset($details[$listKey]) || !is_array($details[$listKey])) {
                continue;
            }

            $rows = array_values(array_filter($details[$listKey], 'is_array'));
            $total = 0;
            foreach ($totalKeys as $totalKey) {
                if (isset($details[$totalKey]) && is_numeric($details[$totalKey])) {
                    $total = (int) $details[$totalKey];
                    break;
                }
            }
            if ($total === 0) {
                $total = count($rows);
            }

            if ($rows === [] && $total === 0) {
                continue;
            }

            return [
                'rows' => $rows,
                'total' => $total,
                'kind' => $listKey,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{key: string, label: string, value: string}>
     */
    private function factsForFileMetadata(array $row): array
    {
        $facts = [];
        if (isset($row['identifier']) && is_string($row['identifier'])) {
            $path = str_replace('\\', '/', trim($row['identifier']));
            $this->pushFact($facts, 'file', basename($path));
        }
        $this->pushFact($facts, 'altText', $row['alternative'] ?? ($row['alt'] ?? null));
        $this->pushFact($facts, 'title', $row['title'] ?? null);
        $this->pushFact($facts, 'description', $row['description'] ?? null);
        $this->pushFact($facts, 'uid', $row['file_uid'] ?? ($row['uid'] ?? null));

        return $facts;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{key: string, label: string, value: string}>
     */
    private function factsForPage(array $row): array
    {
        $facts = [];
        $this->pushFact($facts, 'title', $row['title'] ?? null);
        $this->pushFact($facts, 'uid', $row['uid'] ?? null);
        $this->pushFact($facts, 'pid', $row['pid'] ?? null);
        $this->pushFact($facts, 'slug', $row['slug'] ?? null);
        if (isset($row['doktype'])) {
            $this->pushFact($facts, 'doktype', $this->doktypeLabel((int) $row['doktype']));
        }
        if (isset($row['hidden'])) {
            $this->pushFact($facts, 'hidden', $this->translator->translate(
                ((int) $row['hidden'] === 1) ? 'agent.value.hidden' : 'agent.value.visible',
            ));
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{key: string, label: string, value: string}>
     */
    private function factsForContent(array $row): array
    {
        $facts = [];
        $header = trim((string) ($row['header'] ?? ''));
        $this->pushFact($facts, 'header', $header !== '' ? $header : null);
        $this->pushFact($facts, 'uid', $row['uid'] ?? null);
        $this->pushFact($facts, 'page', $row['pid'] ?? null);
        $this->pushFact($facts, 'doktype', $row['CType'] ?? ($row['ctype'] ?? null));

        return $facts;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array{key: string, label: string, value: string}>
     */
    private function factsForList(string $toolName, array $rows): array
    {
        $facts = [
            $this->fact('items', (string) count($rows)),
        ];

        $examples = $this->extractRowLabels($rows);
        if ($examples !== []) {
            $facts[] = $this->fact('examples', implode(', ', $examples));
        }

        return $facts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function extractRowLabels(array $rows): array
    {
        $labels = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = $this->rowDisplayLabel($row);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowDisplayLabel(array $row): string
    {
        if (isset($row['identifier']) && is_string($row['identifier'])) {
            $path = str_replace('\\', '/', trim($row['identifier']));
            $basename = basename($path);
            $uid = (int) ($row['file_uid'] ?? $row['uid'] ?? 0);

            return $uid > 0 ? $basename . ' [' . $uid . ']' : $basename;
        }

        $label = trim((string) ($row['title'] ?? $row['header'] ?? $row['name'] ?? ''));
        $uid = isset($row['uid']) ? (string) (int) $row['uid'] : '';
        if ($label === '' && $uid === '') {
            return '';
        }

        return $label !== '' ? ($uid !== '' ? $label . ' [' . $uid . ']' : $label) : ('#' . $uid);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{key: string, label: string, value: string}>
     */
    private function factsGeneric(array $row): array
    {
        $facts = [];
        foreach (self::IMPORTANT_KEYS as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = match ($key) {
                'doktype' => $this->doktypeLabel((int) $row[$key]),
                'hidden' => $this->translator->translate(
                    ((int) $row[$key] === 1) ? 'agent.value.hidden' : 'agent.value.visible',
                ),
                default => $row[$key],
            };
            $this->pushFact($facts, $key, $value);
            if (count($facts) >= 6) {
                break;
            }
        }

        return $facts;
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function buildDeterministicSummary(string $editorLabel, array $facts, mixed $details): string
    {
        if (is_array($details) && isset($details['summary']) && is_string($details['summary'])) {
            $fromPayload = trim($details['summary']);
            if ($fromPayload !== '') {
                return $fromPayload;
            }
        }

        if ($facts === []) {
            if (is_scalar($details)) {
                return (string) $details;
            }

            $label = $editorLabel !== '' ? $editorLabel : $this->translator->translate('agent.result.action');

            return $this->translator->translate('agent.result.completed', [$label]);
        }

        $titleFact = null;
        foreach ($facts as $fact) {
            if (in_array($fact['key'], ['title', 'header', 'name'], true)) {
                $titleFact = $fact['value'];
                break;
            }
        }

        if ($titleFact !== null) {
            $uid = $this->factValue($facts, 'uid');
            $line = $titleFact;
            if ($uid !== null) {
                $line .= ' · uid ' . $uid;
            }
            $extras = [];
            foreach ($facts as $fact) {
                if (in_array($fact['key'], ['title', 'header', 'name', 'uid'], true)) {
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

    private function tryLlmSummary(string $editorLabel, mixed $details, ?int $pageId): ?string
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
            $this->languageResolver->backendLanguageInstruction(),
            'Summarize the following tool result in 2-4 short sentences.',
            'Highlight the important fields (title, uid, status, errors).',
            'Do not invent data. Do not use markdown — no asterisks, bullet lists, or headings. Plain sentences only.',
            'Action: ' . ($editorLabel !== '' ? $editorLabel : 'Tool result'),
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
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function shouldSkipLlmSummary(array $facts): bool
    {
        $hasExamples = $this->hasFact($facts, 'examples') || $this->hasFact($facts, 'includes');
        if (!$hasExamples) {
            return false;
        }

        foreach (self::COUNT_FACT_KEYS as $key) {
            if ($this->hasFact($facts, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function buildListLeadSummary(string $toolName, array $facts, mixed $details): ?string
    {
        $count = $this->primaryCountFromFacts($facts);
        if ($count === null) {
            return null;
        }

        if ($count <= 0) {
            return $this->emptyCollectionMessage($toolName);
        }

        $examples = $this->factValue($facts, 'examples') ?? $this->factValue($facts, 'includes');
        $subject = $this->collectionSubjectLabel($toolName, $facts);
        $lead = $count === 1
            ? $this->translator->translate('agent.result.foundOne', [$subject])
            : $this->translator->translate('agent.result.foundMany', [$count, $subject]);

        if ($examples !== null && $examples !== '') {
            $lead .= "\n\n" . $this->translator->translate('agent.result.examplesLead', [$examples]);
            if ($count > 5) {
                $lead .= "\n\n" . $this->translator->translate('agent.result.openDetails', [$count]);
            }
        }

        return $lead;
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function buildToolSpecificContent(string $toolName, array $facts, mixed $details): ?string
    {
        if ($toolName === 't3aa_list_files_missing_alt_text') {
            $total = $this->primaryCountFromFacts($facts) ?? 0;
            if ($total === 0) {
                return $this->translator->translate('agent.result.noMissingAltText');
            }

            $lead = $this->buildListLeadSummary($toolName, $facts, $details)
                ?? $this->translator->translate('agent.result.foundMany', [
                    $total,
                    $this->translator->translate('agent.subject.missingAltText'),
                ]);

            return $lead . "\n\n" . $this->translator->translate('agent.result.nextAltTextHint');
        }

        if ($toolName === 'file_list' && is_array($details)) {
            $files = (int) ($details['totalFiles'] ?? 0);
            $folders = (int) ($details['totalDirectories'] ?? 0);
            $path = trim((string) ($details['directoryPath'] ?? $details['path'] ?? ''));
            $lead = $path !== ''
                ? $this->translator->translate('agent.result.folderContentsIn', [$path, $files, $folders])
                : $this->translator->translate('agent.result.folderContents', [$files, $folders]);
            $examples = $this->factValue($facts, 'examples');
            if ($examples !== null && $examples !== '') {
                $lead .= "\n\n" . $this->translator->translate('agent.result.examplesLead', [$examples]);
            }

            return $lead;
        }

        $actionMessage = $this->buildActionSuccessContent($details);
        if ($actionMessage !== null) {
            return $actionMessage;
        }

        return null;
    }

    private function buildActionSuccessContent(mixed $details): ?string
    {
        if (!is_array($details)) {
            return null;
        }

        $labelKeys = [
            'deleted' => 'agent.result.doneDeleted',
            'published' => 'agent.result.donePublished',
            'discarded' => 'agent.result.doneDiscarded',
            'cleared' => 'agent.result.doneCacheCleared',
            'copied' => 'agent.result.doneCopied',
            'moved' => 'agent.result.doneMoved',
            'created' => 'agent.result.doneCreated',
            'updated' => 'agent.result.doneUpdated',
        ];

        foreach ($labelKeys as $key => $labelKey) {
            if (($details[$key] ?? false) === true) {
                return $this->translator->translate($labelKey);
            }
        }

        if (isset($details['updatedFields']) && is_array($details['updatedFields']) && $details['updatedFields'] !== []) {
            $count = count($details['updatedFields']);

            return $count === 1
                ? $this->translator->translate('agent.result.doneOneFieldUpdated')
                : $this->translator->translate('agent.result.doneFieldsUpdated', [$count]);
        }

        if (($details['success'] ?? false) === true && isset($details['message']) && is_scalar($details['message'])) {
            $message = trim((string) $details['message']);
            if ($message !== '') {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function primaryCountFromFacts(array $facts): ?int
    {
        foreach (self::COUNT_FACT_KEYS as $key) {
            $value = $this->factValue($facts, $key);
            if ($value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function collectionSubjectLabel(string $toolName, array $facts): string
    {
        if ($toolName === 't3aa_list_files_missing_alt_text') {
            return $this->translator->translate('agent.subject.missingAltText');
        }

        foreach ($facts as $fact) {
            if (in_array($fact['key'], self::COUNT_FACT_KEYS, true) && $fact['key'] !== 'items') {
                return $this->translator->translate('agent.subject.' . $fact['key']);
            }
        }

        return $this->translator->translate('agent.subject.items');
    }

    private function emptyCollectionMessage(string $toolName): string
    {
        return $this->translator->translate(match ($toolName) {
            't3aa_list_files_missing_alt_text' => 'agent.result.emptyMissingAltText',
            'file_search' => 'agent.result.emptyMatchingFiles',
            'pages_search' => 'agent.result.emptyMatchingPages',
            'content_search' => 'agent.result.emptyMatchingContentElements',
            'record_search' => 'agent.result.emptyMatchingRecords',
            'pages_list' => 'agent.result.emptyChildPages',
            'content_list' => 'agent.result.emptyContentElements',
            'redirect_list' => 'agent.result.emptyRedirects',
            'scheduler_list' => 'agent.result.emptyScheduledTasks',
            default => 'agent.result.emptyItems',
        });
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function hasFact(array $facts, string $key): bool
    {
        return $this->factValue($facts, $key) !== null;
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function pushFact(array &$facts, string $key, mixed $value): void
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return;
        }
        $string = trim((string) $value);
        if ($string === '') {
            return;
        }
        $facts[] = $this->fact($key, $string);
    }

    /**
     * @return array{key: string, label: string, value: string}
     */
    private function fact(string $key, string $value): array
    {
        return ['key' => $key, 'label' => $this->factLabel($key), 'value' => $value];
    }

    private function factLabel(string $key): string
    {
        $labelKey = self::FACT_LABEL_KEYS[$key] ?? '';

        return $labelKey !== '' ? $this->translator->translate($labelKey) : $this->humanizeKey($key);
    }

    /**
     * @param list<array{key: string, label: string, value: string}> $facts
     */
    private function factValue(array $facts, string $key): ?string
    {
        foreach ($facts as $fact) {
            if ($fact['key'] === $key) {
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
        $labelKey = match ($doktype) {
            1 => 'agent.doktype.standard',
            3 => 'agent.doktype.externalUrl',
            4 => 'agent.doktype.shortcut',
            6 => 'agent.doktype.backendUserSection',
            7 => 'agent.doktype.mountPoint',
            199 => 'agent.doktype.separator',
            254 => 'agent.doktype.folder',
            255 => 'agent.doktype.recycler',
            default => '',
        };

        return $labelKey !== ''
            ? $this->translator->translate($labelKey)
            : $this->translator->translate('agent.doktype.unknown', [$doktype]);
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
