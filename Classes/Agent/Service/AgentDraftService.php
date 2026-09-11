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

use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

/**
 * Builds inline draft card payloads from tool plans (BE-INLINE-DRAFT).
 *
 * @internal
 */
final class AgentDraftService
{
    public function __construct(
        private readonly AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildDraftCard(ToolPlan $plan, string $severity): array
    {
        $draftId = bin2hex(random_bytes(8));

        if (($plan->context['planKind'] ?? '') === SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION) {
            $displayArguments = is_array($plan->context['displayArguments'] ?? null)
                ? array_values(array_filter($plan->context['displayArguments'], 'is_array'))
                : [];

            return [
                'draftId' => $draftId,
                'kind' => SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION,
                'tool' => $plan->toolName,
                'action' => $plan->action,
                'severity' => $severity,
                'summary' => (string) ($plan->context['summary'] ?? ''),
                'arguments' => $displayArguments,
                'fields' => [],
                'destructiveArmed' => false,
                'elicitation' => true,
                'totalFields' => 0,
                'safeFieldCount' => 0,
            ];
        }

        $fields = [];

        foreach ($plan->fields as $field) {
            $fields[] = [
                'key' => $field->key,
                'table' => $field->table,
                'uid' => $field->uid,
                'field' => $field->field,
                'current' => $this->formatValue($field->currentValue),
                'proposed' => $this->formatValue($field->proposedValue),
                'kept' => true,
                'safe' => $this->lowRiskFieldMatrix->isSafeField($field->table, $field->field),
            ];
        }

        return [
            'draftId' => $draftId,
            'tool' => $plan->toolName,
            'action' => $plan->action,
            'severity' => $severity,
            'fields' => $fields,
            'destructiveArmed' => false,
            'elicitation' => true,
            'totalFields' => count($fields),
            'safeFieldCount' => $this->lowRiskFieldMatrix->countSafeFields($plan),
        ];
    }

    /**
     * @param array<string, mixed> $draftCard
     * @param array<string, mixed> $arguments
     */
    public function persistDraft(
        array $draftCard,
        ToolPlan $plan,
        array $arguments,
        AgentDraftSession $session,
        ?string $flow = null,
    ): void {
        $draftId = (string) ($draftCard['draftId'] ?? '');
        if ($draftId === '') {
            return;
        }

        $payload = [
            'plan' => $plan->toArray(),
            'arguments' => $arguments,
            'severity' => (string) ($draftCard['severity'] ?? ''),
            'destructiveArmed' => false,
            'createdAt' => time(),
        ];
        if ($flow !== null && $flow !== '') {
            $payload['flow'] = $flow;
        }

        $session->storeDraft($draftId, $payload);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }
}
