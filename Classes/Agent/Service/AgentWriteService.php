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

use NITSAN\NsT3AF\Mcp\Dto\PreviewResult;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

/**
 * Applies kept draft fields via DataHandler and read-backs results (T13).
 * Preview drafts (`flow === agent_preview`) apply via DualMode context content params.
 *
 * @internal
 */
final class AgentWriteService
{
    private const FLOW_AGENT_PREVIEW = 'agent_preview';

    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly RecordService $recordService,
        private readonly AgentDraftSession $draftSession,
        private readonly McpPlaygroundService $playgroundService,
        private readonly AgentToolResultPresenter $toolResultPresenter,
        private readonly AgentTranslator $translator,
        private readonly AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
    ) {}

    public function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param list<string> $keptFieldKeys
     * @return array<string, mixed>
     */
    public function apply(string $draftId, array $keptFieldKeys, ?string $correlationId = null): array
    {
        $stored = $this->draftSession->getDraft($draftId);
        if ($stored === null) {
            throw new \RuntimeException($this->translator->translate('agent.write.draftNotFound'), 1712003200);
        }

        if ((string) ($stored['flow'] ?? '') === self::FLOW_AGENT_PREVIEW) {
            throw new \RuntimeException($this->translator->translate('agent.write.previewNeedsSelections'), 1712003212);
        }

        $severity = (string) ($stored['severity'] ?? '');
        if ($severity === 'destructive' && ($stored['destructiveArmed'] ?? false) !== true) {
            throw new \RuntimeException($this->translator->translate('agent.write.destructiveNeedsConfirmation'), 1712003201);
        }

        $plan = ToolPlan::fromArray(is_array($stored['plan'] ?? null) ? $stored['plan'] : []);
        $correlationId ??= $this->generateCorrelationId();

        if (($plan->context['planKind'] ?? '') === SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION) {
            return $this->applyToolConfirmation($plan, $stored, $correlationId, $draftId);
        }

        $applyResult = $this->dataHandlerService->applyFilteredPlan($plan, $keptFieldKeys, $correlationId);
        $readback = $this->readBack($plan, $keptFieldKeys, $applyResult['affected'] ?? []);

        $changeId = bin2hex(random_bytes(8));
        $this->draftSession->storeChange($changeId, [
            'correlationId' => $correlationId,
            'plan' => $plan->toArray(),
            'keptFieldKeys' => $keptFieldKeys,
            'undoFields' => $this->buildUndoFields($plan, $keptFieldKeys),
            'appliedAt' => time(),
        ]);
        $this->draftSession->removeDraft($draftId);

        return [
            'changeId' => $changeId,
            'correlationId' => $correlationId,
            'appliedCount' => count($applyResult['appliedFieldKeys'] ?? []),
            'totalCount' => count($plan->fields),
            'readback' => $readback,
            'action' => $plan->action,
            'tool' => $plan->toolName,
        ];
    }

    /**
     * Apply a preview suggestions draft via DualMode context-mode content params.
     *
     * @param array<string, int>    $selections fieldKey => variant index
     * @param array<string, string> $edits      optional editor overrides (fieldKey => text)
     * @return array<string, mixed>
     */
    public function applySuggestions(
        string $draftId,
        array $selections,
        array $edits = [],
        bool $editedByEditor = false,
        string $applyMode = 'all',
        ?string $correlationId = null,
    ): array {
        $stored = $this->draftSession->getDraft($draftId);
        if ($stored === null) {
            throw new \RuntimeException($this->translator->translate('agent.write.draftNotFound'), 1712003200);
        }
        if ((string) ($stored['flow'] ?? '') !== self::FLOW_AGENT_PREVIEW) {
            throw new \RuntimeException($this->translator->translate('agent.write.notPreviewDraft'), 1712003213);
        }

        $severity = (string) ($stored['severity'] ?? '');
        if ($severity === 'destructive' && ($stored['destructiveArmed'] ?? false) !== true) {
            throw new \RuntimeException($this->translator->translate('agent.write.destructiveNeedsConfirmation'), 1712003201);
        }

        $preview = PreviewResult::fromArray(
            is_array($stored['previewResult'] ?? null) ? $stored['previewResult'] : [],
        );
        $toolName = $preview->tool !== ''
            ? $preview->tool
            : (string) ($stored['tool'] ?? '');
        if ($toolName === '') {
            throw new \RuntimeException($this->translator->translate('agent.write.missingToolName'), 1712003210);
        }

        $normalizedSelections = $this->normalizeSelections($selections);
        $resolved = $preview->resolveSelections($normalizedSelections);
        foreach ($edits as $fieldKey => $value) {
            if (!is_string($fieldKey) || $fieldKey === '' || !is_scalar($value)) {
                continue;
            }
            $resolved[$fieldKey] = (string) $value;
        }

        if ($resolved === []) {
            throw new \RuntimeException($this->translator->translate('agent.write.noSuggestionSelections'), 1712003214);
        }

        $table = (string) ($preview->target['table'] ?? '');
        if ($applyMode === 'safe') {
            $safeKeys = $this->lowRiskFieldMatrix->filterSafePreviewFieldKeys($table, array_keys($resolved));
            $resolved = array_intersect_key($resolved, array_flip($safeKeys));
            if ($resolved === []) {
                throw new \RuntimeException($this->translator->translate('agent.draft.noSafeFields'), 1712003215);
            }
        }

        if ($edits !== [] && !$editedByEditor) {
            throw new \RuntimeException($this->translator->translate('agent.write.editsNeedEditorFlag'), 1712003216);
        }

        $baseArguments = is_array($stored['arguments'] ?? null) ? $stored['arguments'] : [];
        $arguments = $this->buildContextModeArguments($toolName, $baseArguments, $resolved);
        $correlationId ??= $this->generateCorrelationId();

        $invokeResult = $this->playgroundService->invokeWithMode(
            $toolName,
            $arguments,
            McpModeResolver::MODE_CONTEXT,
        );
        if (($invokeResult['success'] ?? false) !== true) {
            throw new \RuntimeException(
                (string) ($invokeResult['message'] ?? $this->translator->translate('agent.write.toolInvocationFailed')),
                1712003211,
            );
        }

        $pageId = isset($arguments['pageId']) ? (int) $arguments['pageId'] : null;
        if ($pageId !== null && $pageId <= 0) {
            $pageId = null;
        }

        $presentation = $this->toolResultPresenter->present(
            $toolName,
            $invokeResult['result'] ?? null,
            true,
            (string) ($invokeResult['message'] ?? ''),
            $pageId,
        );

        $changeId = bin2hex(random_bytes(8));
        $this->draftSession->storeChange($changeId, [
            'correlationId' => $correlationId,
            'previewResult' => $preview->toArray(),
            'appliedValues' => $resolved,
            'undoFields' => $this->buildPreviewUndoFields($preview, $resolved),
            'appliedAt' => time(),
            'suggestionsApply' => true,
        ]);
        $this->draftSession->removeDraft($draftId);

        return [
            'changeId' => $changeId,
            'correlationId' => $correlationId,
            'appliedCount' => count($resolved),
            'totalCount' => count($preview->fields),
            'appliedValues' => $resolved,
            'readback' => [],
            'action' => 'apply_suggestions',
            'tool' => $toolName,
            'suggestionsApply' => true,
            'presentation' => $presentation,
            'latencyMs' => (int) ($invokeResult['latencyMs'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $selections
     * @return array<string, int>
     */
    private function normalizeSelections(array $selections): array
    {
        $normalized = [];
        foreach ($selections as $fieldKey => $variantIndex) {
            if (!is_string($fieldKey) || $fieldKey === '') {
                continue;
            }
            $normalized[$fieldKey] = (int) $variantIndex;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>  $baseArguments
     * @param array<string, string> $resolvedValues
     * @return array<string, mixed>
     */
    private function buildContextModeArguments(string $toolName, array $baseArguments, array $resolvedValues): array
    {
        $arguments = $baseArguments;
        unset($arguments['variants'], $arguments['aiProvider']);

        if ($toolName === 't3ai_generate_seo_batch') {
            $arguments['entries'] = $this->buildBatchEntries($resolvedValues);

            return $arguments;
        }

        // File metadata DualMode uses discrete #[McpContentParam] args (altText/title/description).
        if ($toolName === 't3aa_update_file_metadata' || array_key_exists('altText', $resolvedValues)) {
            return array_merge($arguments, $resolvedValues);
        }

        $arguments['fields'] = $resolvedValues;

        return $arguments;
    }

    /**
     * @param array<string, string> $resolvedValues
     * @return list<array<string, mixed>>
     */
    private function buildBatchEntries(array $resolvedValues): array
    {
        $byPage = [];
        foreach ($resolvedValues as $key => $value) {
            if (!str_contains($key, ':')) {
                continue;
            }
            [$pageIdRaw, $fieldKey] = explode(':', $key, 2);
            $pageId = (int) $pageIdRaw;
            if ($pageId <= 0 || $fieldKey === '') {
                continue;
            }
            $byPage[$pageId][$fieldKey] = $value;
        }

        $entries = [];
        foreach ($byPage as $pageId => $fields) {
            $entries[] = array_merge(['pageId' => $pageId], $fields);
        }

        return $entries;
    }

    /**
     * @param array<string, string> $resolved
     * @return list<array{table: string, uid: int, field: string, previousValue: mixed, action: string}>
     */
    private function buildPreviewUndoFields(PreviewResult $preview, array $resolved): array
    {
        $currentByKey = [];
        foreach ($preview->fields as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key !== '') {
                $currentByKey[$key] = (string) ($field['current'] ?? '');
            }
        }

        $table = (string) ($preview->target['table'] ?? '');
        $uid = (int) ($preview->target['uid'] ?? 0);
        $undo = [];
        foreach ($resolved as $fieldKey => $_) {
            $undo[] = [
                'table' => $table,
                'uid' => $uid,
                'field' => $fieldKey,
                'previousValue' => $currentByKey[$fieldKey] ?? '',
                'action' => 'update',
            ];
        }

        return $undo;
    }

    /**
     * @param list<string> $keptFieldKeys
     * @param list<array{table: string, uid: int}> $affected
     * @return list<array<string, mixed>>
     */
    private function readBack(ToolPlan $plan, array $keptFieldKeys, array $affected): array
    {
        $keptFields = $plan->keptFields($keptFieldKeys);
        $readback = [];

        foreach ($affected as $record) {
            $table = (string) ($record['table'] ?? '');
            $uid = (int) ($record['uid'] ?? 0);
            if ($table === '' || $uid <= 0) {
                continue;
            }

            $fieldNames = [];
            foreach ($keptFields as $field) {
                if ($field->table === $table && ($field->uid === $uid || $plan->action === 'create' || $plan->action === 'copy')) {
                    if ($field->field !== '_record' && !str_starts_with($field->field, '_')) {
                        $fieldNames[] = $field->field;
                    }
                }
            }

            if ($fieldNames === []) {
                $fieldNames = ['uid'];
            }

            $row = $this->recordService->findByUid($table, $uid, array_values(array_unique($fieldNames)));
            $readback[] = [
                'table' => $table,
                'uid' => $uid,
                'values' => $row ?? [],
            ];
        }

        return $readback;
    }

    /**
     * @param list<string> $keptFieldKeys
     * @return list<array{table: string, uid: int, field: string, previousValue: mixed, action: string}>
     */
    private function buildUndoFields(ToolPlan $plan, array $keptFieldKeys): array
    {
        $undo = [];
        foreach ($plan->keptFields($keptFieldKeys) as $field) {
            $undo[] = [
                'table' => $field->table,
                'uid' => $field->uid,
                'field' => $field->field,
                'previousValue' => $field->currentValue,
                'action' => $plan->action,
            ];
        }

        return $undo;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function applyToolConfirmation(ToolPlan $plan, array $stored, string $correlationId, string $draftId): array
    {
        $toolName = $plan->toolName;
        if ($toolName === '') {
            throw new \RuntimeException($this->translator->translate('agent.write.missingToolName'), 1712003210);
        }

        $arguments = is_array($stored['arguments'] ?? null) ? $stored['arguments'] : [];
        if ($arguments === [] && is_array($plan->context['arguments'] ?? null)) {
            $arguments = $plan->context['arguments'];
        }

        $invokeResult = $this->playgroundService->invoke($toolName, $arguments);
        if (($invokeResult['success'] ?? false) !== true) {
            throw new \RuntimeException(
                (string) ($invokeResult['message'] ?? $this->translator->translate('agent.write.toolInvocationFailed')),
                1712003211,
            );
        }

        $pageId = isset($arguments['pageId']) ? (int) $arguments['pageId'] : null;
        if ($pageId !== null && $pageId <= 0) {
            $pageId = null;
        }

        $presentation = $this->toolResultPresenter->present(
            $toolName,
            $invokeResult['result'] ?? null,
            true,
            (string) ($invokeResult['message'] ?? ''),
            $pageId,
        );

        $changeId = bin2hex(random_bytes(8));
        $this->draftSession->storeChange($changeId, [
            'correlationId' => $correlationId,
            'plan' => $plan->toArray(),
            'keptFieldKeys' => [],
            'undoFields' => [],
            'appliedAt' => time(),
            'toolConfirmation' => true,
        ]);
        $this->draftSession->removeDraft($draftId);

        return [
            'changeId' => $changeId,
            'correlationId' => $correlationId,
            'appliedCount' => 1,
            'totalCount' => 1,
            'readback' => [],
            'action' => 'invoke',
            'tool' => $toolName,
            'toolConfirmation' => true,
            'presentation' => $presentation,
        ];
    }
}
