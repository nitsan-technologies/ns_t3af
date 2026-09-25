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

use NITSAN\NsT3AF\Agent\Entitlement\EntitlementResolver;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogRepository;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * View-model builder for the AI Agent settings module (T21).
 *
 * @internal
 */
final readonly class AgentSettingsPresenter
{
    public function __construct(
        private McpToolIntrospectorService $toolIntrospector,
        private EntitlementResolver $entitlementResolver,
        private AgentDemandCounter $demandCounter,
        private AgentTurnRepository $turnRepository,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $catalog = $this->buildCatalog();
        $bySeverity = [
            ToolSeverity::Read->value => [],
            ToolSeverity::Write->value => [],
            ToolSeverity::Destructive->value => [],
            'unclassified' => [],
        ];
        $byOwner = [];

        foreach ($catalog['all'] as $tool) {
            $severity = (string) ($tool['severity'] ?? '');
            $bucket = $severity !== '' && isset($bySeverity[$severity]) ? $severity : 'unclassified';
            $bySeverity[$bucket][] = $tool;

            $owner = (string) ($tool['ownerExtensionKey'] ?? 'ns_t3af');
            $byOwner[$owner] ??= [
                'ownerExtensionKey' => $owner,
                'ownerLabel' => (string) ($tool['ownerLabel'] ?? $owner),
                'total' => 0,
                'executable' => 0,
                'locked' => 0,
            ];
            $byOwner[$owner]['total']++;
            if (($tool['executable'] ?? false) === true) {
                $byOwner[$owner]['executable']++;
            } else {
                $byOwner[$owner]['locked']++;
            }
        }

        ksort($byOwner);

        $operationalStatuses = $this->entitlementResolver->getAllStatuses();
        $agentAvailable = $this->entitlementResolver->isExecutable('ns_t3af');

        return [
            'overview' => [
                'totalTools' => count($catalog['all']),
                'executableTools' => count($catalog['executable']),
                'lockedTools' => count($catalog['locked']),
                'ownerCount' => count($byOwner),
                'owners' => array_values($byOwner),
                'availability' => [
                    [
                        'item' => 'module.aiAgent.availability.agent',
                        'status' => $agentAvailable ? 'module.aiAgent.availability.status.enabled' : 'module.aiAgent.availability.status.disabled',
                        'source' => 'module.aiAgent.availability.source.permission',
                    ],
                    [
                        'item' => 'module.aiAgent.availability.hotkey',
                        'status' => 'module.aiAgent.availability.status.local',
                        'source' => 'module.aiAgent.availability.source.browser',
                    ],
                    [
                        'item' => 'module.aiAgent.availability.suggestions',
                        'status' => 'module.aiAgent.availability.status.off',
                        'source' => 'module.aiAgent.availability.source.policy',
                    ],
                ],
            ],
            'severityPolicy' => $bySeverity,
            'severityPolicyTable' => [
                [
                    'severity' => ToolSeverity::Read->value,
                    'label' => 'module.aiAgent.severity.read',
                    'behavior' => 'module.aiAgent.severity.read.behavior',
                    'count' => count($bySeverity[ToolSeverity::Read->value]),
                ],
                [
                    'severity' => ToolSeverity::Write->value,
                    'label' => 'module.aiAgent.severity.write',
                    'behavior' => 'module.aiAgent.severity.write.behavior',
                    'count' => count($bySeverity[ToolSeverity::Write->value]),
                ],
                [
                    'severity' => ToolSeverity::Destructive->value,
                    'label' => 'module.aiAgent.severity.destructive',
                    'behavior' => 'module.aiAgent.severity.destructive.behavior',
                    'count' => count($bySeverity[ToolSeverity::Destructive->value]),
                ],
                [
                    'severity' => 'unclassified',
                    'label' => 'module.aiAgent.severity.unclassified',
                    'behavior' => 'module.aiAgent.severity.unclassified.behavior',
                    'count' => count($bySeverity['unclassified']),
                ],
            ],
            'governanceMatrix' => [
                [
                    'control' => 'module.aiAgent.governance.row.creditCap',
                    'source' => 'module.aiAgent.governance.source.group',
                    'effect' => 'module.aiAgent.governance.effect.creditCap',
                ],
                [
                    'control' => 'module.aiAgent.governance.row.dailyCap',
                    'source' => 'module.aiAgent.governance.source.group',
                    'effect' => 'module.aiAgent.governance.effect.dailyCap',
                ],
                [
                    'control' => 'module.aiAgent.governance.row.allowlist',
                    'source' => 'module.aiAgent.governance.source.group',
                    'effect' => 'module.aiAgent.governance.effect.allowlist',
                ],
                [
                    'control' => 'module.aiAgent.governance.row.modelOverride',
                    'source' => 'module.aiAgent.governance.source.group',
                    'effect' => 'module.aiAgent.governance.effect.modelOverride',
                ],
                [
                    'control' => 'module.aiAgent.governance.row.piiMasking',
                    'source' => 'module.aiAgent.governance.source.group',
                    'effect' => 'module.aiAgent.governance.effect.piiMasking',
                ],
                [
                    'control' => 'module.aiAgent.governance.row.turnGuard',
                    'source' => 'module.aiAgent.governance.source.agent',
                    'effect' => 'module.aiAgent.governance.effect.turnGuard',
                    'effectArguments' => [
                        (string) AgentGovernanceGuard::TURN_GUARD_WARN,
                        (string) AgentGovernanceGuard::TURN_GUARD_ABORT,
                    ],
                ],
            ],
            'audit' => [
                'coverage' => [
                    ['item' => 'module.aiAgent.audit.coverage.toolInvocations', 'recorded' => true],
                    ['item' => 'module.aiAgent.audit.coverage.turnCorrelation', 'recorded' => true],
                    ['item' => 'module.aiAgent.audit.coverage.conversationTranscript', 'recorded' => false],
                    ['item' => 'module.aiAgent.audit.coverage.promptContent', 'recorded' => false],
                ],
                'recentLogs' => $this->recentAgentLogs(30),
                'recentTurns' => $this->turnRepository->recentTurns(20),
            ],
            'demand' => [
                'signals' => $this->demandCounter->topSignals(25),
                'totalActivations' => $this->demandCounter->totalActivations(),
                'sharingEnabled' => false,
                'sharingDisabledReason' => 'module.aiAgent.demand.sharingDisabledReason',
            ],
            'operationalStatuses' => $operationalStatuses,
        ];
    }

    /**
     * @return array{all: list<array<string, mixed>>, executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    private function buildCatalog(): array
    {
        $executable = [];
        $locked = [];
        $all = [];

        foreach ($this->toolIntrospector->listTools() as $tool) {
            $entry = $this->normalizeToolEntry($tool);
            $all[] = $entry;
            if (($entry['executable'] ?? false) === true) {
                $executable[] = $entry;
            } else {
                $locked[] = $entry;
            }
        }

        usort($all, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'all' => $all,
            'executable' => $executable,
            'locked' => $locked,
        ];
    }

    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function normalizeToolEntry(array $tool): array
    {
        $ownerKey = (string) ($tool['ownerExtensionKey'] ?? 'ns_t3af');
        if ($ownerKey === '') {
            $ownerKey = 'ns_t3af';
        }

        $severity = ToolSeverity::tryFromString((string) ($tool['severity'] ?? ''));
        $executable = $severity !== null && $this->entitlementResolver->isExecutable($ownerKey);

        return [
            'name' => (string) ($tool['name'] ?? ''),
            'description' => (string) ($tool['description'] ?? ''),
            'severity' => $severity?->value,
            'severityLabel' => $severity?->label() ?? '',
            'ownerExtensionKey' => $ownerKey,
            'ownerLabel' => $ownerKey === 'ns_t3af' ? 'AI Foundation' : $ownerKey,
            'executable' => $executable,
        ];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function recentAgentLogs(int $limit): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(McpToolLogRepository::TABLE);
        $qb->getRestrictions()->removeAll();

        return array_values($qb->select('crdate', 'tool_name', 'success', 'latency_ms', 'correlation_id', 'arguments_hash', 'be_user')
            ->from(McpToolLogRepository::TABLE)
            ->where($qb->expr()->eq('call_type', $qb->createNamedParameter(AgentAuditLogger::CALL_TYPE)))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative());
    }
}
