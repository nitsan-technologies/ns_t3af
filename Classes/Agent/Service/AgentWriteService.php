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

use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

/**
 * Applies kept draft fields via DataHandler and read-backs results (T13).
 *
 * @internal
 */
final class AgentWriteService
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly RecordService $recordService,
        private readonly AgentDraftSession $draftSession,
        private readonly McpPlaygroundService $playgroundService,
        private readonly AgentToolResultPresenter $toolResultPresenter,
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
            throw new \RuntimeException('Draft not found or expired.', 1712003200);
        }

        $severity = (string) ($stored['severity'] ?? '');
        if ($severity === 'destructive' && ($stored['destructiveArmed'] ?? false) !== true) {
            throw new \RuntimeException('Destructive draft requires confirmation first.', 1712003201);
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
            throw new \RuntimeException('Tool confirmation plan is missing a tool name.', 1712003210);
        }

        $arguments = is_array($stored['arguments'] ?? null) ? $stored['arguments'] : [];
        if ($arguments === [] && is_array($plan->context['arguments'] ?? null)) {
            $arguments = $plan->context['arguments'];
        }

        $invokeResult = $this->playgroundService->invoke($toolName, $arguments);
        if (($invokeResult['success'] ?? false) !== true) {
            throw new \RuntimeException(
                (string) ($invokeResult['message'] ?? 'Tool invocation failed.'),
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
