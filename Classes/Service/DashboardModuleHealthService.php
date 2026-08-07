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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Mcp\Service\McpServerStatusService;
use NITSAN\NsT3AF\Registry\ExtensionSettingsScopeRegistry;
use NITSAN\NsT3AF\Registry\PromptCatalogProviderRegistry;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final class DashboardModuleHealthService
{
    private const ERROR_RATE_DEGRADED_THRESHOLD = 2.0;

    public function __construct(
        private readonly ProviderRepositoryInterface $providers,
        private readonly McpServerStatusService $mcpServerStatus,
        private readonly AiPromptsService $aiPromptsService,
        private readonly BrandContextProfileRepositoryInterface $brandContextProfiles,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly BrandContextCompletenessCalculator $brandContextCompleteness,
        private readonly ConnectionPool $connectionPool,
        private readonly SchedulerCliTaskService $schedulerCliTaskService,
        private readonly ExtensionSettingsScopeRegistry $extensionSettingsScopeRegistry,
        private readonly PromptCatalogProviderRegistry $promptCatalogProviderRegistry,
    ) {}

    /**
     * @param list<array{provider:string,requests:int,failed:int}> $providerStats
     * @param array<string, string> $uris
     * @param list<array<string, mixed>> $scheduledTasks
     * @return list<array{id:string,label:string,detail:string,status:string,href:string}>
     */
    public function build(
        ServerRequestInterface $request,
        array $providerStats,
        array $uris,
        array $scheduledTasks,
        int $storagePid = 0,
    ): array {
        $enabledCount = 0;
        $degradedCount = 0;
        $statsByProvider = [];
        foreach ($providerStats as $row) {
            $statsByProvider[(string) ($row['provider'] ?? '')] = $row;
        }
        $siteProviders = $storagePid > 0
            ? $this->providers->findAllByStoragePid($storagePid, includeHidden: false)
            : [];
        foreach ($siteProviders as $provider) {
            if ($provider->identifier === CreditsProviderIdentifier::IDENTIFIER) {
                continue;
            }
            if (!$provider->isEnabled) {
                continue;
            }
            $enabledCount++;
            $stats = $statsByProvider[$provider->identifier] ?? null;
            $requests = (int) ($stats['requests'] ?? 0);
            $failed = (int) ($stats['failed'] ?? 0);
            $errorRate = $requests > 0 ? ($failed / $requests) * 100 : 0.0;
            if ($errorRate >= self::ERROR_RATE_DEGRADED_THRESHOLD || trim($provider->apiKeyCipher) === '') {
                $degradedCount++;
            }
        }

        $mcpStatus = $this->mcpServerStatus->build($request);
        $mcpOnline = (int) ($mcpStatus['online'] ?? 0) === 1;
        $activeClients = (int) ($mcpStatus['activeClients'] ?? 0);

        $promptOverview = $this->aiPromptsService->buildOverviewData([
            'search' => '',
            'extension' => 'all',
            'title' => '',
            'text' => '',
            'promptType' => 'all',
            'scope' => 'all',
        ]);
        $hasPromptCatalog = $this->promptCatalogProviderRegistry->getAvailableProviders() !== [];
        $promptDetail = $hasPromptCatalog
            ? (int) ($promptOverview['kpis']['totalPrompts'] ?? 0) . ' templates ready'
            : 'No prompt catalog registered';

        $failingTask = $this->firstFailingSchedulerTask();
        $schedulerDetail = (int) ($scheduledTasks['active'] ?? 0) . ' scheduled';
        if ($failingTask !== '') {
            $schedulerDetail .= ' · 1 failed';
        }

        $aiContextUri = (string) ($uris['aiContext'] ?? '');
        $brandContextDetail = $this->buildBrandContextHealthDetail($request);

        $items = [
            [
                'id' => 'providers',
                'label' => 'AI Providers',
                'detail' => $enabledCount . ' active' . ($degradedCount > 0 ? ' · ' . $degradedCount . ' degraded' : ''),
                'status' => $degradedCount > 0 ? 'warn' : ($enabledCount > 0 ? 'ok' : 'error'),
                'href' => (string) ($uris['providers'] ?? ''),
            ],
            [
                'id' => 'mcp_server',
                'label' => 'MCP Server',
                'detail' => $mcpOnline ? 'Running' : 'Offline',
                'status' => $mcpOnline ? 'ok' : 'error',
                'href' => (string) ($uris['mcpServer'] ?? ''),
            ],
            [
                'id' => 'mcp_connectors',
                'label' => 'MCP Connectors',
                'detail' => $activeClients . ' active clients',
                'status' => $activeClients > 0 ? 'ok' : 'warn',
                'href' => (string) ($uris['mcpServer'] ?? ''),
            ],
            [
                'id' => 'ai_context',
                'label' => 'AI Context',
                'detail' => $brandContextDetail['detail'],
                'status' => $brandContextDetail['status'],
                'href' => $aiContextUri,
            ],
            [
                'id' => 'ai_features',
                'label' => 'AI Features',
                'detail' => $this->countInstalledAiFeatureExtensions() . ' extensions detected',
                'status' => 'ok',
                'href' => (string) ($uris['aiFeatures'] ?? ''),
            ],
            [
                'id' => 'ai_prompts',
                'label' => 'AI Prompts',
                'detail' => $promptDetail,
                'status' => $hasPromptCatalog ? 'ok' : 'warn',
                'href' => (string) ($uris['aiPrompts'] ?? ''),
            ],
            [
                'id' => 'scheduler',
                'label' => 'Scheduler & CLI',
                'detail' => $schedulerDetail,
                'status' => $failingTask !== '' ? 'warn' : ((int) ($scheduledTasks['active'] ?? 0) > 0 ? 'ok' : 'warn'),
                'href' => (string) ($uris['schedulerCli'] ?? ''),
            ],
        ];

        return $items;
    }

    /**
     * @param list<array{id:string,label:string,detail:string,status:string,href:string}> $items
     * @return array{healthy:int,warnings:int,total:int}
     */
    public function summarize(array $items): array
    {
        $healthy = 0;
        $warnings = 0;
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === 'ok') {
                $healthy++;
            } elseif ($status === 'warn') {
                $warnings++;
            }
        }

        return [
            'healthy' => $healthy,
            'warnings' => $warnings,
            'total' => count($items),
        ];
    }

    /**
     * @return array{detail: string, status: string}
     */
    private function buildBrandContextHealthDetail(ServerRequestInterface $request): array
    {
        $resolution = $this->siteStorageContext->resolveFromRequest($request);
        if (!$resolution->isResolved()) {
            return [
                'detail' => 'Select a site page',
                'status' => 'warn',
            ];
        }

        $default = $this->brandContextProfiles->findDefault($resolution->storagePid);
        if ($default === null) {
            return [
                'detail' => 'No default profile',
                'status' => 'warn',
            ];
        }

        $percent = $this->brandContextCompleteness->calculatePercent($default);
        $name = $default->brandName !== '' ? $default->brandName : 'Default profile';

        return [
            'detail' => $name . ' · ' . $percent . '% complete',
            'status' => $percent >= 100 ? 'ok' : 'warn',
        ];
    }

    private function countInstalledAiFeatureExtensions(): int
    {
        return max(1, count($this->extensionSettingsScopeRegistry->getAvailableProviders()));
    }

    private function firstFailingSchedulerTask(): string
    {
        foreach ($this->schedulerCliTaskService->listTasks(['status' => 'all']) as $task) {
            if ((int) ($task['hasFailure'] ?? 0) === 1) {
                return (string) ($task['commandName'] ?? $task['command'] ?? '');
            }
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('tx_scheduler_task');
            $qb->getRestrictions()->removeAll();
            $row = $qb->select('description', 'lastexecution_failure')
                ->from('tx_scheduler_task')
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->neq('lastexecution_failure', $qb->createNamedParameter('')),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            if (is_array($row)) {
                return (string) ($row['description'] ?? '');
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
