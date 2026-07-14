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
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;
use NITSAN\NsT3AF\Mcp\Service\McpServerStatusService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Builds dashboard onboarding checklist rows from live providers + analytics.
 *
 * @internal
 */
final class SetupChecklistService
{
    public function __construct(
        private readonly ProviderRepositoryInterface $providers,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly McpServerStatusService $mcpServerStatus,
        private readonly PromptOverviewProviderInterface $aiPromptsService,
        private readonly SchedulerCliTaskService $schedulerCliTaskService,
        private readonly BrandContextProfileRepositoryInterface $brandContextProfiles,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly BrandContextCompletenessCalculator $brandContextCompleteness,
        private readonly EnvironmentRequirementService $environmentRequirements,
    ) {}

    /**
     * @param array<string, mixed> $analytics
     * @param array<string, mixed> $creditsDashboard
     *
     * @return array{
     *   percent: int,
     *   done: int,
     *   warnings: int,
     *   incomplete: int,
     *   items: list<array{
     *     status: string,
     *     titleKey: string,
     *     descKey: string,
     *     descArgs?: list<string>,
     *     actionRoute: string,
     *     actionTabKey?: string|null
     *   }>
     * }
     */
    public function build(
        array $analytics,
        RequestLogProviderScope $scope,
        bool $creditsModeSelected,
        array $creditsDashboard,
        ServerRequestInterface $request,
    ): array {
        $items = $creditsModeSelected
            ? $this->buildCreditsItems($analytics, $creditsDashboard, $request)
            : $this->buildOwnKeysItems($analytics, $request);

        // Host/PHP gaps first — admins must fix these before product onboarding helps.
        $items = [...$this->environmentRequirements->failingChecklistItems(), ...$items];

        $done = count(array_filter($items, static fn(array $row): bool => $row['status'] === 'ok'));
        $warnings = count(array_filter($items, static fn(array $row): bool => $row['status'] === 'warn'));
        $incomplete = count(array_filter(
            $items,
            static fn(array $row): bool => in_array($row['status'], ['error', 'pending'], true),
        ));

        return [
            'percent' => (int) min(100, round(100 * $done / max(1, count($items)))),
            'done' => $done,
            'warnings' => $warnings,
            'incomplete' => $incomplete,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $analytics
     * @param array<string, mixed> $creditsDashboard
     * @return list<array{status: string, titleKey: string, descKey: string, descArgs?: list<string>, actionRoute: string, actionTabKey?: string|null}>
     */
    private function buildCreditsItems(array $analytics, array $creditsDashboard, ServerRequestInterface $request): array
    {
        $items = [];
        $active = $this->creditModeResolver->isActive();
        $items[] = [
            'status' => $active ? 'ok' : 'error',
            'titleKey' => 'checklist.credits.active.title',
            'descKey' => $active ? 'checklist.credits.active.descOk' : 'checklist.credits.active.descMissing',
            'actionRoute' => $active ? 't3af_dashboard.overview' : 't3af_dashboard.buy_credits',
        ];

        $loaded = (bool) ($creditsDashboard['loaded'] ?? false);
        $items[] = [
            'status' => $loaded ? 'ok' : 'warn',
            'titleKey' => 'checklist.credits.balance.title',
            'descKey' => $loaded ? 'checklist.credits.balance.descOk' : 'checklist.credits.balance.descMissing',
            'actionRoute' => 't3af_dashboard.credits_pricing',
        ];

        $totalRequests = (int) ($analytics['totals']['totalRequests'] ?? 0);
        $items[] = [
            'status' => $totalRequests > 0 ? 'ok' : 'warn',
            'titleKey' => 'checklist.activity.title',
            'descKey' => $totalRequests > 0 ? 'checklist.activity.descOk' : 'checklist.activity.descQuiet',
            'descArgs' => [(string) $totalRequests],
            'actionRoute' => 't3af_dashboard.ai_usage',
            'actionTabKey' => 'aiUsage',
        ];

        $items[] = $this->mcpChecklistItem($request);
        $items[] = $this->brandContextChecklistItem($request);
        $items[] = $this->schedulerChecklistItem($analytics);
        $items[] = $this->promptsChecklistItem();

        return $items;
    }

    /**
     * @param array<string, mixed> $analytics
     * @return list<array{status: string, titleKey: string, descKey: string, descArgs?: list<string>, actionRoute: string, actionTabKey?: string|null}>
     */
    private function buildOwnKeysItems(array $analytics, ServerRequestInterface $request): array
    {
        $resolution = $this->siteStorageContext->resolveFromRequest($request);
        $storagePid = $resolution->isResolved() ? $resolution->storagePid : 0;

        $providersVisible = array_values(array_filter(
            $storagePid > 0
                ? $this->providers->findAllByStoragePid($storagePid, includeHidden: false)
                : [],
            static fn($p) => $p->identifier !== CreditsProviderIdentifier::IDENTIFIER,
        ));
        $enabled = array_values(array_filter($providersVisible, static fn($p) => $p->isEnabled));
        $enabledCount = count($enabled);
        $registeredTotal = count($providersVisible);

        $items = [];
        $items[] = [
            'status' => $enabledCount >= 1 ? 'ok' : 'error',
            'titleKey' => 'checklist.providers.title',
            'descKey' => $enabledCount >= 1 ? 'checklist.providers.descOk' : 'checklist.providers.descMissing',
            'descArgs' => [(string) $registeredTotal],
            'actionRoute' => 't3af_dashboard.providers',
            'actionTabKey' => 'providers',
        ];

        $default = $storagePid > 0 ? $this->providers->findDefault($storagePid) : null;
        if ($default !== null && $default->identifier !== CreditsProviderIdentifier::IDENTIFIER) {
            $title = $default->title !== '' ? $default->title : $default->identifier;
            $model = $default->modelId !== '' ? $default->modelId : '—';
            $items[] = [
                'status' => 'ok',
                'titleKey' => 'checklist.default.title',
                'descKey' => 'checklist.default.descOk',
                'descArgs' => [$title, $model],
                'actionRoute' => 't3af_dashboard.providers',
                'actionTabKey' => 'providers',
            ];
        } else {
            $items[] = [
                'status' => 'warn',
                'titleKey' => 'checklist.default.title',
                'descKey' => 'checklist.default.descMissing',
                'actionRoute' => 't3af_dashboard.providers',
                'actionTabKey' => 'providers',
            ];
        }

        $missingKey = false;
        foreach ($enabled as $provider) {
            if (trim($provider->apiKeyCipher) === '') {
                $missingKey = true;
                break;
            }
        }
        $items[] = [
            'status' => $enabledCount === 0 ? 'pending' : ($missingKey ? 'warn' : 'ok'),
            'titleKey' => 'checklist.keys.title',
            'descKey' => $enabledCount === 0
                ? 'checklist.keys.descPending'
                : ($missingKey ? 'checklist.keys.descMissing' : 'checklist.keys.descOk'),
            'actionRoute' => 't3af_dashboard.providers',
            'actionTabKey' => 'providers',
        ];

        $items[] = $this->mcpChecklistItem($request);
        $items[] = $this->brandContextChecklistItem($request);
        $items[] = $this->budgetChecklistItem();
        $items[] = $this->schedulerChecklistItem($analytics, true);
        $items[] = $this->promptsChecklistItem();

        return $items;
    }

    /**
     * @return array{status: string, titleKey: string, descKey: string, descArgs?: list<string>, actionRoute: string, actionTabKey?: string|null}
     */
    private function mcpChecklistItem(ServerRequestInterface $request): array
    {
        $status = $this->mcpServerStatus->build($request);
        $online = (int) ($status['online'] ?? 0) === 1;

        return [
            'status' => $online ? 'ok' : 'warn',
            'titleKey' => 'checklist.mcp.title',
            'descKey' => $online ? 'checklist.mcp.descOk' : 'checklist.mcp.descOffline',
            'actionRoute' => 't3af_dashboard.mcp_server',
            'actionTabKey' => 'mcpServer',
        ];
    }

    /**
     * @return array{status: string, titleKey: string, descKey: string, descArgs?: list<string>, actionRoute: string, actionTabKey?: string|null}
     */
    private function brandContextChecklistItem(ServerRequestInterface $request): array
    {
        $resolution = $this->siteStorageContext->resolveFromRequest($request);
        if (!$resolution->isResolved()) {
            return [
                'status' => 'warn',
                'titleKey' => 'checklist.brandContext.title',
                'descKey' => 'checklist.brandContext.descMissing',
                'actionRoute' => 't3af_dashboard.ai_context',
                'actionTabKey' => 'aiContext',
            ];
        }

        $default = $this->brandContextProfiles->findDefault($resolution->storagePid);
        if ($default === null) {
            return [
                'status' => 'warn',
                'titleKey' => 'checklist.brandContext.title',
                'descKey' => 'checklist.brandContext.descMissing',
                'actionRoute' => 't3af_dashboard.ai_context',
                'actionTabKey' => 'aiContext',
            ];
        }

        $percent = $this->brandContextCompleteness->calculatePercent($default);
        if ($percent < 100) {
            return [
                'status' => 'warn',
                'titleKey' => 'checklist.brandContext.title',
                'descKey' => 'checklist.brandContext.descIncomplete',
                'descArgs' => [$default->brandName !== '' ? $default->brandName : 'Default profile', (string) $percent],
                'actionRoute' => 't3af_dashboard.ai_context',
                'actionTabKey' => 'aiContext',
            ];
        }

        return [
            'status' => 'ok',
            'titleKey' => 'checklist.brandContext.title',
            'descKey' => 'checklist.brandContext.descOk',
            'descArgs' => [$default->brandName !== '' ? $default->brandName : 'Default profile', (string) $percent],
            'actionRoute' => 't3af_dashboard.ai_context',
            'actionTabKey' => 'aiContext',
        ];
    }

    /**
     * @return array{status: string, titleKey: string, descKey: string, actionRoute: string}
     */
    private function budgetChecklistItem(): array
    {
        $hasBudget = $this->instanceHasBudgetCapConfigured();

        return [
            'status' => $hasBudget ? 'ok' : 'warn',
            'titleKey' => 'checklist.budget.title',
            'descKey' => $hasBudget ? 'checklist.budget.descOk' : 'checklist.budget.descMissing',
            'actionRoute' => 't3af_dashboard.for_developers',
        ];
    }

    /**
     * @param array<string, mixed> $analytics
     * @return array{status: string, titleKey: string, descKey: string, descArgs?: list<string>, actionRoute: string, actionTabKey?: string|null}
     */
    private function schedulerChecklistItem(array $analytics, bool $withTaskName = false): array
    {
        $scheduledTasks = $analytics['scheduledTasks'] ?? ['total' => 0, 'active' => 0, 'failing' => 0];
        $schedulerFailing = (int) ($scheduledTasks['failing'] ?? 0);
        $schedulerTotal = (int) ($scheduledTasks['total'] ?? 0);

        if ($schedulerFailing > 0) {
            $taskName = $withTaskName ? $this->firstFailingTaskLabel() : '';

            return [
                'status' => 'warn',
                'titleKey' => 'checklist.scheduler.title',
                'descKey' => $taskName !== '' ? 'checklist.scheduler.descFailingNamed' : 'checklist.scheduler.descFailing',
                'descArgs' => $taskName !== '' ? [$taskName] : [(string) $schedulerFailing],
                'actionRoute' => 't3af_dashboard.scheduler_cli',
                'actionTabKey' => 'schedulerCli',
            ];
        }

        if ($schedulerTotal > 0) {
            return [
                'status' => 'ok',
                'titleKey' => 'checklist.scheduler.title',
                'descKey' => 'checklist.scheduler.descOk',
                'actionRoute' => 't3af_dashboard.scheduler_cli',
                'actionTabKey' => 'schedulerCli',
            ];
        }

        return [
            'status' => 'warn',
            'titleKey' => 'checklist.scheduler.title',
            'descKey' => 'checklist.scheduler.descNone',
            'actionRoute' => 't3af_dashboard.scheduler_cli',
            'actionTabKey' => 'schedulerCli',
        ];
    }

    /**
     * @return array{status: string, titleKey: string, descKey: string, descArgs?: list<string>, actionRoute: string, actionTabKey?: string|null}
     */
    private function promptsChecklistItem(): array
    {
        $overview = $this->aiPromptsService->buildOverviewData([
            'search' => '',
            'extension' => 'all',
            'title' => '',
            'text' => '',
            'promptType' => 'all',
            'scope' => 'all',
        ]);
        $total = (int) ($overview['kpis']['totalPrompts'] ?? 0);

        return [
            'status' => $total > 0 ? 'warn' : 'error',
            'titleKey' => 'checklist.prompts.title',
            'descKey' => $total > 0 ? 'checklist.prompts.descDefaults' : 'checklist.prompts.descEmpty',
            'descArgs' => [(string) $total],
            'actionRoute' => 't3af_dashboard.ai_prompts',
            'actionTabKey' => 'aiPrompts',
        ];
    }

    private function instanceHasBudgetCapConfigured(): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return false;
        }
        $config = $user->getTSconfig()['nst3af.']['budget.'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        return trim((string) ($config['maxCost'] ?? '')) !== ''
            || trim((string) ($config['maxTokens'] ?? '')) !== ''
            || trim((string) ($config['maxRequests'] ?? '')) !== '';
    }

    private function firstFailingTaskLabel(): string
    {
        foreach ($this->schedulerCliTaskService->listTasks(['status' => 'all']) as $task) {
            if ((int) ($task['hasFailure'] ?? 0) === 1) {
                $name = (string) ($task['commandName'] ?? $task['command'] ?? '');

                return $name !== '' ? $name : (string) ($task['description'] ?? '');
            }
        }

        return '';
    }
}
