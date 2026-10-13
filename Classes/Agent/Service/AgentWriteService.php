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
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
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

    private const GLOSSARY_TABLE = 'tx_nst3ai_domain_model_glossary';

    /** Tools whose apply creates one new page; Undo deletes that page. */
    private const PAGE_CREATE_TOOLS = [
        't3ai_create_page_simple',
        't3ai_create_page_advanced',
        't3ai_create_blog_simple',
        't3ai_create_blog_advanced',
    ];

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
            // Only a draft another click could still apply goes back.
            $this->releaseDraft($draftId, $stored, !$exception instanceof AgentApplyNotRetryableException);
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

        $attachNote = $this->attachPendingFileAfterCreate(
            $stored,
            $plan,
            $readback,
            is_array($applyResult['affected'] ?? null) ? $applyResult['affected'] : [],
        );
        if ($attachNote !== '') {
            $readback[] = ['table' => '_note', 'uid' => 0, 'values' => ['summary' => $attachNote]];
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
            'totalCount' => count(array_filter($plan->fields, static fn(ToolPlanField $field): bool => AgentDraftService::isShownOnCard($plan, $field))),
            'readback' => $readback,
            'action' => $plan->action,
            'tool' => $plan->toolName,
            'placement' => $this->placementOf($plan),
            'table' => $plan->fields[0]->table ?? '',
            'notAllowedFields' => is_array($plan->context['notAllowedFields'] ?? null) ? array_values(array_map('strval', $plan->context['notAllowedFields'])) : [],
            'attachNote' => $attachNote,
        ];
    }

    /**
     * After "new content element" create Apply: attach the uploaded file that waited on the draft.
     *
     * @param array<string, mixed>                 $stored
     * @param list<array<string, mixed>>           $readback
     * @param list<array{table: string, uid: int}> $affected
     */
    private function attachPendingFileAfterCreate(array $stored, ToolPlan $plan, array $readback, array $affected): string
    {
        $fileUid = (int) ($stored['pendingAttachFileUid'] ?? 0);
        if ($fileUid <= 0 || $plan->action !== 'create' || $plan->toolName !== 'write_table') {
            return '';
        }
        $contentUid = 0;
        $cType = '';
        foreach ($affected as $record) {
            if (($record['table'] ?? '') === 'tt_content' && (int) ($record['uid'] ?? 0) > 0) {
                $contentUid = (int) $record['uid'];
                break;
            }
        }
        foreach ($readback as $entry) {
            if (!is_array($entry) || ($entry['table'] ?? '') !== 'tt_content') {
                continue;
            }
            if ($contentUid <= 0) {
                $contentUid = (int) ($entry['uid'] ?? 0);
            }
            $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
            $cType = strtolower(trim((string) ($values['CType'] ?? '')));
            if ($contentUid > 0) {
                break;
            }
        }
        if ($cType === '') {
            foreach ($plan->fields as $field) {
                if ($field->table === 'tt_content' && strtolower($field->field) === 'ctype') {
                    $cType = strtolower(trim((string) $field->proposedValue));
                    break;
                }
            }
        }
        if ($contentUid <= 0) {
            return '';
        }
        $fieldName = AgentAttachTargetResolver::pickField($cType !== '' ? $cType : 'textmedia', ['assets', 'image']) ?? 'assets';
        try {
            $result = $this->playgroundService->invokeWithMode(
                'file_reference_add',
                [
                    'table' => 'tt_content',
                    'uid' => $contentUid,
                    'fieldName' => $fieldName,
                    'fileUids' => (string) $fileUid,
                ],
                McpModeResolver::MODE_NATIVE,
            );
        } catch (\Throwable) {
            return '';
        }
        if (($result['success'] ?? false) !== true) {
            return '';
        }

        return sprintf('Attached file uid %d to tt_content uid %d (%s).', $fileUid, $contentUid, $fieldName);
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

    /**
     * The tool said the same call would fail again ({"error": "…", "retryable": false}).
     */
    public static function failureIsFinal(mixed $result): bool
    {
        if (is_string($result)) {
            try {
                $result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return false;
            }
        }

        return is_array($result) && ($result['retryable'] ?? true) === false;
    }

    /**
     * What Undo removes after a confirmed tool ran. Attaching files and saving a glossary term can be
     * undone: exactly the new references or rows go (a changed term gets its old text back), never the
     * files or the record they were attached to. Other tools have no undo.
     *
     * @return list<array<string, mixed>>
     */
    public static function toolUndoFields(string $toolName, mixed $result): array
    {
        if ($toolName === 't3ai_glossary_save') {
            return self::glossaryUndoFields($result);
        }
        if ($toolName !== 'file_reference_add') {
            return [];
        }
        if (is_string($result)) {
            try {
                $result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        if (!is_array($result)) {
            return [];
        }
        $parent = [
            'table' => (string) ($result['table'] ?? ''),
            'uid' => (int) ($result['uid'] ?? 0),
            'field' => (string) ($result['fieldName'] ?? ''),
        ];
        if ($parent['table'] === '' || $parent['uid'] <= 0 || $parent['field'] === '') {
            return [];
        }

        $undo = [];
        foreach (is_array($result['referenceUids'] ?? null) ? $result['referenceUids'] : [] as $referenceUid) {
            if (is_numeric($referenceUid) && (int) $referenceUid > 0) {
                $undo[] = [
                    'table' => 'sys_file_reference',
                    'uid' => (int) $referenceUid,
                    'field' => '_record',
                    'previousValue' => null,
                    'action' => 'create',
                    'parent' => $parent,
                ];
            }
        }

        return $undo;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function glossaryUndoFields(mixed $result): array
    {
        $payload = self::decodeToolResultPayload($result);
        $overlayUid = (int) ($payload['termId'] ?? 0);
        if (($payload['saved'] ?? false) !== true || $overlayUid <= 0) {
            return [];
        }
        $table = self::GLOSSARY_TABLE;
        $parentUid = (int) ($payload['parentTermId'] ?? 0);

        if (($payload['overlayCreated'] ?? false) === true) {
            $undo = [['table' => $table, 'uid' => $overlayUid, 'field' => '_record', 'previousValue' => null, 'action' => 'create']];
            if (($payload['parentCreated'] ?? false) === true && $parentUid > 0) {
                $undo[] = ['table' => $table, 'uid' => $parentUid, 'field' => '_record', 'previousValue' => null, 'action' => 'create'];
            }

            return $undo;
        }

        $undo = [[
            'table' => $table,
            'uid' => $overlayUid,
            'field' => 'term',
            'previousValue' => (string) ($payload['previousTarget'] ?? ''),
            'appliedValue' => (string) ($payload['target'] ?? ''),
            'action' => 'update',
        ]];
        $previousSource = (string) ($payload['previousSource'] ?? '');
        if ($parentUid > 0 && $previousSource !== '' && $previousSource !== (string) ($payload['source'] ?? '')) {
            $undo[] = [
                'table' => $table,
                'uid' => $parentUid,
                'field' => 'term',
                'previousValue' => $previousSource,
                'appliedValue' => (string) ($payload['source'] ?? ''),
                'action' => 'update',
            ];
        }

        return $undo;
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
        $this->releaseDraft($draftId, $stored, ($result['unchanged'] ?? false) === true);

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
        $structureFellBackToDraft = false;
        foreach ($edits as $fieldKey => $value) {
            if (!is_string($fieldKey) || $fieldKey === '' || !is_scalar($value)) {
                continue;
            }
            // Page-structure cards show a bullet outline; accept that outline, or fall back to the draft JSON.
            if ($toolName === 't3ai_create_page_structure' && $fieldKey === 'structure') {
                $draftJson = (string) ($resolved['structure'] ?? '');
                // Custom is selected alone (no variant selection): the draft JSON lives on the preview variants.
                if ($draftJson === '') {
                    $draftJson = self::structureDraftFromPreview($preview);
                }
                $resolvedStructure = AgentPageStructureOutline::resolveEdit((string) $value, $draftJson);
                $resolved['structure'] = $resolvedStructure['structure'];
                $structureFellBackToDraft = $resolvedStructure['fellBackToDraft'];
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

        $resolved = self::withoutUnchangedSuggestions($resolved, $preview->fields, $toolName);
        if ($resolved === []) {
            return [
                'unchanged' => true,
                'suggestionsApply' => false,
                'appliedCount' => 0,
                'appliedValues' => [],
                'tool' => $toolName,
                'readback' => [],
                'undoable' => false,
            ];
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
        if ($structureFellBackToDraft) {
            $note = $this->translator->translate('agent.write.structureOutlineUnread');
            $presentation['content'] = trim((string) ($presentation['content'] ?? '') . ' ' . $note);
        }

        $changeId = bin2hex(random_bytes(8));
        $undoFields = $this->refineSuggestionUndoFields(
            $toolName,
            $invokeResult['result'] ?? null,
            $this->buildPreviewUndoFields($preview, $resolved),
            $preview,
        );
        $this->draftSession->storeChange($changeId, [
            'correlationId' => $correlationId,
            'previewResult' => $preview->toArray(),
            'appliedValues' => $resolved,
            'undoFields' => $undoFields,
            'appliedAt' => time(),
            'suggestionsApply' => true,
            'workspaceId' => self::currentWorkspaceId(),
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
            'structureFellBackToDraft' => $structureFellBackToDraft,
        ];
    }

    /**
     * A chosen suggestion that matches the text already stored is not a change.
     * SEO apply stores a cleaned value, so that cleanup is used for the comparison.
     *
     * @param array<string, string> $resolved
     * @param list<array<string, mixed>> $fields
     * @return array<string, string>
     */
    public static function withoutUnchangedSuggestions(array $resolved, array $fields, string $toolName = ''): array
    {
        $seo = $toolName === 't3ai_generate_all_seo' || $toolName === 't3ai_generate_seo_batch';
        $current = [];
        foreach ($fields as $field) {
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $stored = (string) ($field['current'] ?? '');
            $current[$key] = $seo ? self::storedSeoText($stored) : trim($stored);
        }
        foreach ($resolved as $key => $value) {
            // A page tree is always a create: never treat the structure JSON as "already on the page".
            if ($toolName === 't3ai_create_page_structure' && $key === 'structure') {
                continue;
            }
            $chosen = $seo ? self::storedSeoText($value) : trim($value);
            if (array_key_exists($key, $current) && $chosen === $current[$key]) {
                unset($resolved[$key]);
            }
        }

        return $resolved;
    }

    /**
     * Variant 0's structure JSON from a page-structure preview (used when Custom was selected alone).
     */
    public static function structureDraftFromPreview(PreviewResult $preview): string
    {
        foreach ($preview->variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $values = is_array($variant['values'] ?? null) ? $variant['values'] : [];
            $structure = trim((string) ($values['structure'] ?? ''));
            if ($structure !== '') {
                return $structure;
            }
        }

        return '';
    }

    /**
     * The value SEO apply stores: trim, drop a leaked preview URL, collapse repeated spaces,
     * and drop separators from the start. Kept in step with MassSeoOptimizationService::buildSaveSuggestions().
     */
    public static function storedSeoText(string $value): string
    {
        $value = trim($value);
        $withoutPreviewUrl = preg_replace('#\S*\?type=1696828748\S*#', '', $value);
        $value = is_string($withoutPreviewUrl) ? $withoutPreviewUrl : $value;
        $collapsed = preg_replace('#\s{2,}#', ' ', $value);
        $value = is_string($collapsed) ? $collapsed : $value;
        $value = ltrim($value, " \t\n\r,.:;–—-");

        return trim($value);
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
        if ($toolName === 't3aa_update_file_metadata'
            || array_key_exists('altText', $resolvedValues)
            || array_key_exists('schemaContent', $resolvedValues)
            || array_key_exists('payload', $resolvedValues)
            || array_key_exists('structure', $resolvedValues)
        ) {
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
     * @return list<array{table: string, uid: int, field: string, previousValue: mixed, action: string, appliedValue?: string}>
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
        foreach ($resolved as $fieldKey => $appliedValue) {
            $fieldUid = $uid;
            if (preg_match('/^(\d+):/', $fieldKey, $pagePrefix) === 1 && (int) $pagePrefix[1] > 0) {
                $fieldUid = (int) $pagePrefix[1];
            }
            $undo[] = [
                'table' => $table,
                'uid' => $fieldUid,
                'field' => $columnByKey[$fieldKey] ?? $fieldKey,
                'previousValue' => $currentByKey[$fieldKey] ?? '',
                'appliedValue' => (string) $appliedValue,
                'action' => 'update',
            ];
        }

        return $undo;
    }

    /**
     * Translation DualMode previews the source record but writes a localization overlay.
     * Undo must delete that overlay (create), never blank the source (update).
     *
     * @param list<array{table: string, uid: int, field: string, previousValue: mixed, action: string, appliedValue?: string}> $undoFields
     * @return list<array<string, mixed>>
     */
    private function refineSuggestionUndoFields(
        string $toolName,
        mixed $result,
        array $undoFields,
        PreviewResult $preview,
    ): array {
        if (in_array($toolName, self::PAGE_CREATE_TOOLS, true)) {
            // The preview targets the parent page, but the apply creates a new page: Undo takes that page back.
            $createdPageId = (int) (self::decodeToolResultPayload($result)['pageId'] ?? 0);

            return $createdPageId > 0
                ? [['table' => 'pages', 'uid' => $createdPageId, 'field' => '_record', 'previousValue' => null, 'action' => 'create']]
                : [];
        }
        if ($toolName === 't3ai_create_page_structure') {
            // The result lists every new page. Newest first, so a subpage goes before its parent.
            $ids = array_values(array_filter(array_map('intval', (array) (self::decodeToolResultPayload($result)['createdPageIds'] ?? [])), static fn(int $uid): bool => $uid > 0));
            rsort($ids);

            return array_map(
                static fn(int $uid): array => ['table' => 'pages', 'uid' => $uid, 'field' => '_record', 'previousValue' => null, 'action' => 'create'],
                $ids,
            );
        }
        if (!str_starts_with($toolName, 't3ai_translate_') && $toolName !== 't3ai_record_translate') {
            return $undoFields;
        }

        $payload = self::decodeToolResultPayload($result);
        $localizedUid = (int) (
            $payload['localizedContentUid']
            ?? $payload['localizedNewsId']
            ?? $payload['localizedUid']
            ?? 0
        );
        if ($localizedUid <= 0) {
            return $undoFields;
        }

        $table = (string) ($preview->target['table'] ?? '');
        if ($table === '' && $undoFields !== []) {
            $table = (string) ($undoFields[0]['table'] ?? '');
        }
        if ($table === '') {
            return $undoFields;
        }

        // Fresh localization (or re-apply that materialised an overlay): take the overlay back.
        return [[
            'table' => $table,
            'uid' => $localizedUid,
            'field' => '_record',
            'previousValue' => null,
            'action' => 'create',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeToolResultPayload(mixed $result): array
    {
        if (is_string($result)) {
            try {
                $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            $result = $decoded;
        }

        return is_array($result) ? $result : [];
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
            $message = (string) ($invokeResult['message'] ?? $this->translator->translate('agent.write.toolInvocationFailed'));
            if (self::failureIsFinal($invokeResult['result'] ?? null)) {
                throw new AgentApplyNotRetryableException($message, 1712003211);
            }
            throw new \RuntimeException($message, 1712003211);
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
        $undoFields = self::toolUndoFields($toolName, $invokeResult['result'] ?? null);
        $this->draftSession->storeChange($changeId, [
            'correlationId' => $correlationId,
            'plan' => $plan->toArray(),
            'keptFieldKeys' => [],
            'undoFields' => $undoFields,
            'appliedAt' => time(),
            'workspaceId' => self::currentWorkspaceId(),
            'toolConfirmation' => true,
        ]);
        $this->draftSession->removeDraft($draftId);

        return [
            'changeId' => $changeId,
            'undoable' => AgentUndoService::isUndoable($undoFields),
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
