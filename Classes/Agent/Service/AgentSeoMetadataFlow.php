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

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Multi-step "Generate SEO metadata" starter: pages_get read card → write_table draft.
 *
 * @internal
 */
final readonly class AgentSeoMetadataFlow
{
    /** @var list<string> */
    private const SEO_FIELDS = ['description', 'abstract', 'keywords'];

    public function __construct(
        private McpPlaygroundService $playgroundService,
        private AgentToolResultPresenter $toolResultPresenter,
        private AgentToolPlanResolver $toolPlanResolver,
        private AgentDraftService $draftService,
        private AgentDraftSession $draftSession,
        private AgentAuditLogger $auditLogger,
    ) {}

    /**
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    public function execute(
        int $pageId,
        string $correlationId,
        bool $includePageRead = true,
        bool $draftOnly = false,
    ): array {
        if ($pageId <= 0) {
            return [[
                'role' => 'assistant',
                'content' => $this->translate('agent.starter.generateSeoNeedsPage'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId],
            ]];
        }

        $readArguments = ['uid' => $pageId];
        $readResult = $this->playgroundService->invoke('pages_get', $readArguments);
        $readSuccess = (bool) ($readResult['success'] ?? false);
        $this->auditLogger->logToolInvocation(
            $correlationId,
            'pages_get',
            $readArguments,
            $readSuccess,
            (int) ($readResult['latencyMs'] ?? 0),
            $readSuccess ? null : 'tool_failed',
        );

        $presented = $this->toolResultPresenter->present(
            'pages_get',
            $readResult['result'] ?? null,
            $readSuccess,
            (string) ($readResult['message'] ?? ''),
            $pageId,
        );

        if (!$readSuccess || $presented['error'] !== null) {
            return [[
                'role' => 'assistant',
                'content' => (string) ($presented['error'] ?? $presented['content']),
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 'pages_get',
                    'toolCallLabel' => $this->translate('agent.starter.inspectPage'),
                    'autoRan' => true,
                    'severity' => ToolSeverity::Read->value,
                    'severityLabel' => ToolSeverity::Read->label(),
                    'success' => false,
                    'summary' => (string) $presented['summary'],
                    'facts' => $presented['facts'],
                    'error' => $presented['error'],
                    'correlationId' => $correlationId,
                    'readWithoutConfirmation' => true,
                ],
            ]];
        }

        $pageRecord = $this->decodePageRecord($readResult['result'] ?? null);
        $messages = [];
        if ($includePageRead) {
            $messages[] = [
                'role' => 'assistant',
                'content' => (string) $presented['content'],
                'meta' => $this->buildReadMeta('pages_get', $this->translate('agent.starter.inspectPage'), $presented, $correlationId),
            ];
        }

        if ($draftOnly) {
            return $messages;
        }

        return array_merge($messages, $this->buildSeoDraftMessages($pageId, $pageRecord, $correlationId));
    }

    /**
     * @param array<string, mixed> $pageRecord
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function buildSeoDraftMessages(int $pageId, array $pageRecord, string $correlationId): array
    {
        $messages = [];

        if (!$this->toolPlanResolver->supportsPlanning('write_table')) {
            $messages[] = [
                'role' => 'assistant',
                'content' => $this->translate('agent.turn.planUnsupported', ['write_table']),
                'meta' => ['type' => 'error', 'tool' => 'write_table', 'correlationId' => $correlationId],
            ];

            return $messages;
        }

        $writeArguments = [
            'action' => 'update',
            'tableName' => 'pages',
            'uid' => $pageId,
            'data' => json_encode($this->buildSeoPayload($pageRecord), JSON_THROW_ON_ERROR),
        ];

        try {
            $plan = $this->toolPlanResolver->plan('write_table', $writeArguments);
        } catch (\Throwable $exception) {
            $messages[] = [
                'role' => 'assistant',
                'content' => $this->translate('agent.turn.planFailed', ['write_table', $exception->getMessage()]),
                'meta' => ['type' => 'error', 'tool' => 'write_table', 'correlationId' => $correlationId],
            ];

            return $messages;
        }

        if ($plan->fields === []) {
            $messages[] = [
                'role' => 'assistant',
                'content' => $this->translate('agent.starter.generateSeoNoFields'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId],
            ];

            return $messages;
        }

        $draftCard = $this->draftService->buildDraftCard($plan, ToolSeverity::Write->value);
        $this->draftService->persistDraft($draftCard, $plan, $writeArguments, $this->draftSession, 'generate_seo_metadata');

        $messages[] = [
            'role' => 'assistant',
            'content' => $this->translate('agent.starter.generateSeoDraft'),
            'meta' => [
                'type' => 'inline_draft',
                'tool' => 'write_table',
                'severity' => ToolSeverity::Write->value,
                'draft' => $draftCard,
                'correlationId' => $correlationId,
                'flow' => 'generate_seo_metadata',
            ],
        ];

        return $messages;
    }

    /**
     * @param array<string, mixed> $presented
     * @return array<string, mixed>
     */
    private function buildReadMeta(string $tool, string $label, array $presented, string $correlationId): array
    {
        $meta = [
            'type' => 'tool_result',
            'tool' => $tool,
            'toolCallLabel' => $label,
            'autoRan' => true,
            'severity' => ToolSeverity::Read->value,
            'severityLabel' => ToolSeverity::Read->label(),
            'success' => (bool) ($presented['success'] ?? true),
            'summary' => (string) ($presented['summary'] ?? ''),
            'llmSummary' => $presented['llmSummary'] ?? null,
            'facts' => $presented['facts'] ?? [],
            'error' => $presented['error'] ?? null,
            'latencyMs' => 0,
            'readWithoutConfirmation' => true,
            'correlationId' => $correlationId,
        ];
        if (($presented['details'] ?? null) !== null) {
            $meta['details'] = $presented['details'];
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $pageRecord
     * @return array<string, string>
     */
    private function buildSeoPayload(array $pageRecord): array
    {
        $title = trim((string) ($pageRecord['title'] ?? 'Page'));
        if ($title === '') {
            $title = 'Page';
        }

        $payload = [];
        foreach (self::SEO_FIELDS as $field) {
            $current = trim((string) ($pageRecord[$field] ?? ''));
            if ($current !== '') {
                continue;
            }
            if ($field === 'description') {
                $payload[$field] = $this->translate('agent.starter.generateSeoDescription', [$title]);
            } elseif ($field === 'abstract') {
                $payload[$field] = $this->translate('agent.starter.generateSeoAbstract', [$title]);
            } elseif ($field === 'keywords') {
                $payload[$field] = $this->translate('agent.starter.generateSeoKeywords', [$title]);
            }
        }

        if ($payload === [] && isset($pageRecord['description'])) {
            $payload['description'] = $this->translate('agent.starter.generateSeoDescription', [$title]);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePageRecord(mixed $rawResult): array
    {
        if (is_array($rawResult)) {
            return $rawResult;
        }
        if (!is_string($rawResult) || trim($rawResult) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawResult, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key;
        $value = $languageService instanceof LanguageService
            ? (string) $languageService->sL($label)
            : $key;

        if ($arguments !== [] && $value !== '') {
            return vsprintf($value, $arguments);
        }

        return $value !== '' ? $value : $key;
    }
}
