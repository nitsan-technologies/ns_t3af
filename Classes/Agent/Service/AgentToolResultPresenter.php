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
        private AgentToolEditorLabelService $editorLabelService,
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
        $editorLabel = $this->editorLabelService->resolveByName($toolName);
        $facts = $error === null ? $this->buildFacts($toolName, $details) : [];
        $summary = $error !== null
            ? $error
            : $this->buildDeterministicSummary($editorLabel, $facts, $details);

        $llmSummary = null;
        if ($error === null && !$this->shouldSkipLlmSummary($facts)) {
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
     * @return list<array{label: string, value: string}>
     */
    private function factsForFileDirectoryListing(array $details): array
    {
        $files = is_array($details['files'] ?? null) ? $details['files'] : [];
        $directories = is_array($details['directories'] ?? null) ? $details['directories'] : [];
        $fileCount = (int) ($details['totalFiles'] ?? count($files));
        $folderCount = (int) ($details['totalDirectories'] ?? count($directories));

        $facts = [
            ['label' => 'Files', 'value' => (string) $fileCount],
            ['label' => 'Folders', 'value' => (string) $folderCount],
        ];

        /** @var list<array<string, mixed>> $fileRows */
        $fileRows = array_values(array_filter($files, 'is_array'));
        $examples = $this->extractRowLabels($fileRows);

        return $examples === [] ? $facts : array_merge($facts, [['label' => 'Examples', 'value' => implode(', ', $examples)]]);
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, kind: string} $collection
     * @return list<array{label: string, value: string}>
     */
    private function factsForCollection(string $toolName, array $collection): array
    {
        $total = max(0, (int) ($collection['total'] ?? count($collection['rows'])));
        $rows = $collection['rows'];
        $countLabel = match ($toolName) {
            't3aa_list_files_missing_alt_text' => 'Images missing alt text',
            'file_search' => 'Matching files',
            'pages_search' => 'Matching pages',
            'content_search' => 'Matching content elements',
            'record_search' => 'Matching records',
            'pages_list' => 'Pages',
            'content_list' => 'Content elements',
            'redirect_list' => 'Redirects',
            'scheduler_list' => 'Scheduled tasks',
            default => match ($collection['kind']) {
                'files' => 'Files',
                'records', 'results', 'rows', 'items' => 'Items',
                default => 'Items',
            },
        };

        $facts = [['label' => $countLabel, 'value' => (string) $total]];
        $examples = $this->extractRowLabels($rows);
        if ($examples !== []) {
            $facts[] = ['label' => 'Examples', 'value' => implode(', ', $examples)];
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
     * @return list<array{label: string, value: string}>
     */
    private function factsForFileMetadata(array $row): array
    {
        $facts = [];
        if (isset($row['identifier']) && is_string($row['identifier'])) {
            $path = str_replace('\\', '/', trim($row['identifier']));
            $this->pushFact($facts, 'File', basename($path));
        }
        $this->pushFact($facts, 'Alt text', $row['alternative'] ?? ($row['alt'] ?? null));
        $this->pushFact($facts, 'Title', $row['title'] ?? null);
        $this->pushFact($facts, 'Description', $row['description'] ?? null);
        $this->pushFact($facts, 'UID', $row['file_uid'] ?? ($row['uid'] ?? null));

        return $facts;
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

        $examples = $this->extractRowLabels($rows);
        if ($examples !== []) {
            $facts[] = ['label' => 'Examples', 'value' => implode(', ', $examples)];
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
    private function buildDeterministicSummary(string $editorLabel, array $facts, mixed $details): string
    {
        if ($facts === []) {
            if (is_scalar($details)) {
                return (string) $details;
            }

            $label = $editorLabel !== '' ? $editorLabel : 'Action';

            return sprintf('%s completed successfully.', $label);
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
     * @param list<array{label: string, value: string}> $facts
     */
    private function shouldSkipLlmSummary(array $facts): bool
    {
        $hasExamples = $this->hasFact($facts, 'Examples') || $this->hasFact($facts, 'Includes');
        if (!$hasExamples) {
            return false;
        }

        foreach (['Items', 'Images missing alt text', 'Files', 'Matching files', 'Matching pages', 'Matching content elements', 'Matching records', 'Pages', 'Content elements', 'Redirects', 'Scheduled tasks', 'Records'] as $label) {
            if ($this->hasFact($facts, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{label: string, value: string}> $facts
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

        $examples = $this->factValue($facts, 'Examples') ?? $this->factValue($facts, 'Includes');
        $subject = $this->collectionSubjectLabel($toolName, $facts);
        $lead = $count === 1
            ? sprintf('Found 1 %s.', rtrim($subject, 's'))
            : sprintf('Found %d %s.', $count, $subject);

        if ($examples !== null && $examples !== '') {
            $lead .= "\n\nExamples: " . $examples;
            if ($count > 5) {
                $lead .= sprintf("\n\nOpen Details for the full list (%d total).", $count);
            }
        }

        return $lead;
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function buildToolSpecificContent(string $toolName, array $facts, mixed $details): ?string
    {
        if ($toolName === 't3aa_list_files_missing_alt_text') {
            $total = $this->primaryCountFromFacts($facts) ?? 0;
            if ($total === 0) {
                return 'Good news — no images are missing alt text in this storage.';
            }

            $lead = $this->buildListLeadSummary($toolName, $facts, $details) ?? sprintf('Found %d images missing alt text.', $total);

            return $lead . "\n\nNext: use “Generate file metadata (alt, title)” or ask to write alt text for a specific file.";
        }

        if ($toolName === 'file_list' && is_array($details)) {
            $files = (int) ($details['totalFiles'] ?? 0);
            $folders = (int) ($details['totalDirectories'] ?? 0);
            $path = trim((string) ($details['directoryPath'] ?? $details['path'] ?? ''));
            $location = $path !== '' ? ' in ' . $path : '';
            $lead = sprintf('This folder%s contains %d file(s) and %d subfolder(s).', $location, $files, $folders);
            $examples = $this->factValue($facts, 'Examples');
            if ($examples !== null && $examples !== '') {
                $lead .= "\n\nExamples: " . $examples;
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

        $messages = [
            'deleted' => 'Done — the item was deleted.',
            'published' => 'Done — the workspace change was published.',
            'discarded' => 'Done — the workspace change was discarded.',
            'cleared' => 'Done — the cache was cleared.',
            'copied' => 'Done — the copy was created.',
            'moved' => 'Done — the item was moved.',
            'created' => 'Done — the item was created.',
            'updated' => 'Done — the changes were saved.',
        ];

        foreach ($messages as $key => $message) {
            if (($details[$key] ?? false) === true) {
                return $message;
            }
        }

        if (isset($details['updatedFields']) && is_array($details['updatedFields']) && $details['updatedFields'] !== []) {
            $count = count($details['updatedFields']);

            return $count === 1
                ? 'Done — 1 field was updated.'
                : sprintf('Done — %d fields were updated.', $count);
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
     * @param list<array{label: string, value: string}> $facts
     */
    private function primaryCountFromFacts(array $facts): ?int
    {
        foreach (['Images missing alt text', 'Items', 'Files', 'Matching files', 'Matching pages', 'Matching content elements', 'Matching records', 'Pages', 'Content elements', 'Redirects', 'Scheduled tasks', 'Records'] as $label) {
            $value = $this->factValue($facts, $label);
            if ($value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param list<array{label: string, value: string}> $facts
     */
    private function collectionSubjectLabel(string $toolName, array $facts): string
    {
        if ($toolName === 't3aa_list_files_missing_alt_text') {
            return 'images missing alt text';
        }

        foreach ($facts as $fact) {
            if (in_array($fact['label'], ['Images missing alt text', 'Matching files', 'Matching pages', 'Matching content elements', 'Matching records', 'Pages', 'Content elements', 'Redirects', 'Scheduled tasks'], true)) {
                return strtolower($fact['label']);
            }
        }

        return 'items';
    }

    private function emptyCollectionMessage(string $toolName): string
    {
        return match ($toolName) {
            't3aa_list_files_missing_alt_text' => 'No images are missing alt text in this storage.',
            'file_search' => 'No matching files were found.',
            'pages_search' => 'No matching pages were found.',
            'content_search' => 'No matching content elements were found.',
            'record_search' => 'No matching records were found.',
            'pages_list' => 'No child pages were found.',
            'content_list' => 'No content elements were found on this page.',
            'redirect_list' => 'No redirects were found.',
            'scheduler_list' => 'No scheduled tasks were found.',
            default => 'No items found.',
        };
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
