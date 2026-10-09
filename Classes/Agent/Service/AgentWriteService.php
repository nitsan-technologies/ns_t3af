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
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Applies kept draft fields via DataHandler and read-backs results (T13).
 * Preview drafts (`flow === agent_preview`) apply via DualMode context content params.
 *
 * @internal
 */
final class AgentWriteService
{
    private const FLOW_AGENT_PREVIEW = 'agent_preview';

    /** A prepared change that was not executed within this time is treated as outdated. */
    public const DRAFT_MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly RecordService $recordService,
        private readonly AgentDraftSession $draftSession,
        private readonly McpPlaygroundService $playgroundService,
        private readonly AgentToolResultPresenter $toolResultPresenter,
        private readonly AgentTranslator $translator,
        private readonly AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
        private readonly ?AgentCreatePlacement $placement = null,
    ) {}

    /** @var array<string, LockingStrategyInterface> */
    private array $draftLocks = [];

    /**
     * Takes the draft for this apply: one request holds the lock and the draft is removed up front, so a
     * double click or a second tab finds it gone instead of writing the same change twice. A failed apply
     * puts it back ({@see releaseDraft()}).
     *
     * @return array<string, mixed>
     */
    private function claimDraft(string $draftId): array
    {
        $locker = null;
        try {
            $locker = GeneralUtility::makeInstance(LockFactory::class)->createLocker(
                'nst3af-agent-draft-' . sha1($draftId),
                LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK,
            );
            if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)) {
                throw new \RuntimeException($this->translator->translate('agent.write.alreadyApplying'), 1712003230);
            }
            $this->draftLocks[$draftId] = $locker;
        } catch (LockAcquireWouldBlockException) {
            // Another request is applying this very draft right now.
            throw new \RuntimeException($this->translator->translate('agent.write.alreadyApplying'), 1712003230);
        } catch (\RuntimeException $exception) {
            if ($exception->getCode() === 1712003230) {
                throw $exception;
            }
            // No lock available (unit tests, broken lock directory): the up-front removal below still guards.
        } catch (\Throwable) {
        }

        $stored = $this->draftSession->getDraft($draftId);
        if ($stored === null) {
            $this->unlockDraft($draftId);
            throw new \RuntimeException($this->translator->translate('agent.write.draftNotFound'), 1712003200);
        }
        $this->draftSession->removeDraft($draftId);

        return $stored;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function releaseDraft(string $draftId, array $stored, bool $restore): void
    {
        if ($restore) {
            $this->draftSession->storeDraft($draftId, $stored);
        }
        $this->unlockDraft($draftId);
    }

    private function unlockDraft(string $draftId): void
    {
        $locker = $this->draftLocks[$draftId] ?? null;
        unset($this->draftLocks[$draftId]);
        if ($locker instanceof LockingStrategyInterface) {
            try {
                $locker->release();
            } catch (\Throwable) {
            }
        }
    }

    public function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Old change cards (e.g. from a chat reopened later) must not apply stale content.
     *
     * @param array<string, mixed> $stored
     */
    private function assertDraftIsRecent(array $stored): void
    {
        $createdAt = (int) ($stored['createdAt'] ?? 0);
        if ($createdAt > 0 && time() - $createdAt > self::DRAFT_MAX_AGE_SECONDS) {
            throw new \RuntimeException($this->translator->translate('agent.write.draftTooOld'), 1712003220);
        }
    }

    /**
     * Refuse to overwrite a value somebody else changed after the agent prepared the change:
     * the value the plan was built from must still be what is stored now.
     *
     * @param list<string> $keptFieldKeys
     */
    private function assertRecordsUnchangedSincePlan(ToolPlan $plan, array $keptFieldKeys): void
    {
        if ($plan->action !== 'update') {
            return;
        }

        $changed = [];
        $gone = [];
        foreach ($plan->keptFields($keptFieldKeys) as $field) {
            if ($field->field === '' || str_starts_with($field->field, '_') || $field->uid <= 0 || $field->currentValue === null) {
                continue;
            }
            $record = $this->recordService->findByUid($field->table, $field->uid, [$field->field]);
            if ($record === null) {
                // Deleted (or no longer readable) since the agent prepared the change.
                $gone[$field->table . '#' . $field->uid] = sprintf('%s #%d', $field->table, $field->uid);
                continue;
            }
            if (!array_key_exists($field->field, $record)) {
                continue;
            }
            if (self::comparable($record[$field->field]) !== self::comparable($field->currentValue)) {
                $changed[] = sprintf('%s #%d (%s)', $field->table, $field->uid, $field->field);
            }
        }

        if ($gone !== []) {
            throw new \RuntimeException(
                $this->translator->translate('agent.write.recordGone', [implode(', ', array_slice(array_values($gone), 0, 5))]),
                1712003222,
            );
        }

        if ($changed !== []) {
            throw new \RuntimeException(
                $this->translator->translate('agent.write.changedSince', [implode(', ', array_slice($changed, 0, 5))]),
                1712003221,
            );
        }
    }

    private static function comparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return (string) json_encode($value);
    }

    /**
     * @param list<string> $keptFieldKeys
     * @return array<string, mixed>
     */
    public function apply(string $draftId, array $keptFieldKeys, ?string $correlationId = null): array
    {
        $stored = $this->claimDraft($draftId);
        try {
            $result = $this->applyClaimed($draftId, $stored, $keptFieldKeys, $correlationId);
        } catch (\Throwable $exception) {
            $this->releaseDraft($draftId, $stored, true);
            throw $exception;
        }
        $this->releaseDraft($draftId, $stored, false);

        return $result;
    }

    /**
     * @param array<string, mixed> $stored
     * @param list<string> $keptFieldKeys
     * @return array<string, mixed>
     */
    private function applyClaimed(string $draftId, array $stored, array $keptFieldKeys, ?string $correlationId): array
    {
        if ((string) ($stored['flow'] ?? '') === self::FLOW_AGENT_PREVIEW) {
            throw new \RuntimeException($this->translator->translate('agent.write.previewNeedsSelections'), 1712003212);
        }
        $this->assertDraftIsRecent($stored);

        $severity = (string) ($stored['severity'] ?? '');
        if ($severity === 'destructive' && ($stored['destructiveArmed'] ?? false) !== true) {
            throw new \RuntimeException($this->translator->translate('agent.write.destructiveNeedsConfirmation'), 1712003201);
        }

        $plan = ToolPlan::fromArray(is_array($stored['plan'] ?? null) ? $stored['plan'] : []);
        $correlationId ??= $this->generateCorrelationId();

        if (($plan->context['planKind'] ?? '') === SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION || self::isFalToolPlan($plan)) {
            return $this->applyToolConfirmation($plan, $stored, $correlationId, $draftId);
        }

        $this->assertRecordsUnchangedSincePlan($plan, $keptFieldKeys);

        $applyResult = $this->dataHandlerService->applyFilteredPlan($plan, $keptFieldKeys, $correlationId);
        $readback = $this->readBack($plan, $keptFieldKeys, $applyResult['affected'] ?? []);

        // DataHandler silently skips a field the editor's group may not edit: the card must not claim it was saved.
        $notSaved = $this->notSavedFieldCount($plan, $keptFieldKeys, $readback);
        $keptCount = count($applyResult['appliedFieldKeys'] ?? []);
        if ($notSaved > 0 && $notSaved >= $keptCount) {
            throw new \RuntimeException($this->translator->translate('agent.write.nothingSaved'), 1712003231);
        }

        $changeId = bin2hex(random_bytes(8));
        $undoFields = $this->buildUndoFields($plan, $keptFieldKeys, $applyResult['affected'] ?? [], $readback);
        $this->draftSession->storeChange($changeId, [
            'correlationId' => $correlationId,
            'plan' => $plan->toArray(),
            'keptFieldKeys' => $keptFieldKeys,
            'undoFields' => $undoFields,
            'appliedAt' => time(),
            // Undo runs in the workspace the change was made in, not in whichever one is active later.
            'workspaceId' => self::currentWorkspaceId(),
        ]);
        $this->draftSession->removeDraft($draftId);

        return [
            'changeId' => $changeId,
            'undoable' => AgentUndoService::isUndoable($undoFields),
            'correlationId' => $correlationId,
            'appliedCount' => $keptCount - $notSaved,
            'totalCount' => count($plan->fields),
            'readback' => $readback,
            'action' => $plan->action,
            'tool' => $plan->toolName,
            'placement' => $this->placementOf($plan),
            'table' => $plan->fields[0]->table ?? '',
            'notAllowedFields' => is_array($plan->context['notAllowedFields'] ?? null) ? array_values(array_map('strval', $plan->context['notAllowedFields'])) : [],
        ];
    }

    /**
     * File and folder changes (create folder, rename, move, copy) are plans over pseudo fields of sys_file
     * ("_create", "_rename", ...). They are not records DataHandler can write, so they are applied by running
     * the tool itself, like every other confirmed tool.
     */
    private static function isFalToolPlan(ToolPlan $plan): bool
    {
        if ($plan->toolName === '' || $plan->fields === []) {
            return false;
        }
        foreach ($plan->fields as $field) {
            if ($field->table !== 'sys_file' || !str_starts_with($field->field, '_')) {
                return false;
            }
        }

        return true;
    }

    private static function currentWorkspaceId(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication ? max(0, (int) $user->workspace) : 0;
    }

    private function placementOf(ToolPlan $plan): string
    {
        if ($plan->action !== 'create' || $this->placement === null) {
            return '';
        }

        $table = '';
        foreach ($plan->fields as $field) {
            $table = $field->table;
            break;
        }

        return $this->placement->describe($table, (int) ($plan->context['pid'] ?? 0));
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
        $stored = $this->claimDraft($draftId);
        try {
            $result = $this->applySuggestionsClaimed($draftId, $stored, $selections, $edits, $editedByEditor, $applyMode, $correlationId);
        } catch (\Throwable $exception) {
            $this->releaseDraft($draftId, $stored, true);
            throw $exception;
        }
        $this->releaseDraft($draftId, $stored, false);

        return $result;
    }

    /**
     * @param array<string, mixed>  $stored
     * @param array<string, int>    $selections
     * @param array<string, string> $edits
     * @return array<string, mixed>
     */
    private function applySuggestionsClaimed(
        string $draftId,
        array $stored,
        array $selections,
        array $edits,
        bool $editedByEditor,
        string $applyMode,
        ?string $correlationId,
    ): array {
        if ((string) ($stored['flow'] ?? '') !== self::FLOW_AGENT_PREVIEW) {
            throw new \RuntimeException($this->translator->translate('agent.write.notPreviewDraft'), 1712003213);
        }
        $this->assertDraftIsRecent($stored);

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
        $undoFields = $this->buildPreviewUndoFields($preview, $resolved);
        $this->draftSession->storeChange($changeId, [
            'correlationId' => $correlationId,
            'previewResult' => $preview->toArray(),
            'appliedValues' => $resolved,
            'undoFields' => $undoFields,
            'appliedAt' => time(),
            'suggestionsApply' => true,
        ]);
        $this->draftSession->removeDraft($draftId);

        return [
            'changeId' => $changeId,
            'undoable' => AgentUndoService::isUndoable($undoFields),
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
        $columnByKey = [];
        foreach ($preview->fields as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $currentByKey[$key] = (string) ($field['current'] ?? '');
            $column = trim((string) ($field['column'] ?? ''));
            if ($column !== '') {
                $columnByKey[$key] = $column;
            }
        }

        $table = (string) ($preview->target['table'] ?? '');
        $uid = (int) ($preview->target['uid'] ?? 0);
        $undo = [];
        foreach ($resolved as $fieldKey => $_) {
            $fieldUid = $uid;
            if (preg_match('/^(\d+):/', $fieldKey, $pagePrefix) === 1 && (int) $pagePrefix[1] > 0) {
                $fieldUid = (int) $pagePrefix[1];
            }
            $undo[] = [
                'table' => $table,
                'uid' => $fieldUid,
                'field' => $columnByKey[$fieldKey] ?? $fieldKey,
                'previousValue' => $currentByKey[$fieldKey] ?? '',
                'action' => 'update',
            ];
        }

        return $undo;
    }

    /**
     * Kept fields of an update that are unchanged after the write (value still the old one, not the proposed one).
     *
     * @param list<string> $keptFieldKeys
     * @param list<array<string, mixed>> $readback
     */
    private function notSavedFieldCount(ToolPlan $plan, array $keptFieldKeys, array $readback): int
    {
        if ($plan->action !== 'update') {
            return 0;
        }
        $stored = [];
        foreach ($readback as $row) {
            if (is_array($row) && is_array($row['values'] ?? null)) {
                $stored[(string) ($row['table'] ?? '') . '#' . (int) ($row['uid'] ?? 0)] = $row['values'];
            }
        }
        $count = 0;
        foreach ($plan->keptFields($keptFieldKeys) as $field) {
            $values = $stored[$field->table . '#' . $field->uid] ?? null;
            if (!is_array($values) || !array_key_exists($field->field, $values) || !is_scalar($field->proposedValue)) {
                continue;
            }
            $now = (string) $values[$field->field];
            if ($now !== (string) $field->proposedValue && is_scalar($field->currentValue ?? '') && $now === (string) ($field->currentValue ?? '')) {
                ++$count;
            }
        }

        return $count;
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
     * @param list<array<string, mixed>> $affected records written by the apply (table, uid)
     * @param list<array<string, mixed>> $readback values stored by the apply, to detect later edits
     * @return list<array<string, mixed>>
     */
    private function buildUndoFields(ToolPlan $plan, array $keptFieldKeys, array $affected = [], array $readback = []): array
    {
        $undo = [];
        if ($plan->action === 'create' || $plan->action === 'copy') {
            // The plan only knows the proposed values (and, for a copy, the SOURCE record); the new record's
            // uid exists after the write. Undoing a create or copy means deleting exactly the records that
            // were just written, never the source.
            foreach ($affected as $record) {
                if (!is_array($record) || (int) ($record['uid'] ?? 0) <= 0) {
                    continue;
                }
                $undo[] = [
                    'table' => (string) ($record['table'] ?? ''),
                    'uid' => (int) $record['uid'],
                    'field' => '_record',
                    'previousValue' => null,
                    'action' => 'create',
                ];
            }

            return $undo;
        }

        $stored = [];
        foreach ($readback as $row) {
            if (is_array($row) && is_array($row['values'] ?? null)) {
                $stored[(string) ($row['table'] ?? '') . '#' . (int) ($row['uid'] ?? 0)] = $row['values'];
            }
        }

        foreach ($plan->keptFields($keptFieldKeys) as $field) {
            $entry = [
                'table' => $field->table,
                'uid' => $field->uid,
                'field' => $field->field,
                'previousValue' => $field->currentValue,
                'action' => $plan->action,
            ];
            $values = $stored[$field->table . '#' . $field->uid] ?? null;
            if ($plan->action === 'update' && is_array($values) && array_key_exists($field->field, $values)) {
                $entry['appliedValue'] = $values[$field->field];
            }
            $undo[] = $entry;
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

        $invokeResult = $this->playgroundService->invoke($toolName, $arguments, true);
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
            'undoable' => false,
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
