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

namespace NITSAN\NsT3AF\Controller\Backend;

use NITSAN\NsT3AF\Access\AiUniverseRecordMap;
use NITSAN\NsT3AF\Access\RecordAccessEnforcer;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;
use NITSAN\NsT3AF\Credits\Service\CreditsCheckoutUrlValidator;
use NITSAN\NsT3AF\Credits\Service\CreditsDashboardService;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\AiSysLogRepository;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;
use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpAnalyticsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpCustomToolService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpDashboardOverviewPresenter;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpHealthService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpSecurityService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpSkillHubService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use NITSAN\NsT3AF\Mcp\Service\McpConnectionsService;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use NITSAN\NsT3AF\Mcp\Service\McpPublicUrlService;
use NITSAN\NsT3AF\Mcp\Service\McpServerStatusService;
use NITSAN\NsT3AF\Mcp\Service\McpToolsRegistryService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceProvisionService;
use NITSAN\NsT3AF\Pagination\FixedTotalPaginator;
use NITSAN\NsT3AF\Registry\AiFeatureCardProviderRegistry;
use NITSAN\NsT3AF\Service\AiLogChannelCatalog;
use NITSAN\NsT3AF\Service\AiLogsStatisticsService;
use NITSAN\NsT3AF\Service\AiPromptsBrandContextInfoService;
use NITSAN\NsT3AF\Service\AiPromptsService;
use NITSAN\NsT3AF\Service\AiUniverseActivityLogService;
use NITSAN\NsT3AF\Service\AiUsageAnalyticsService;
use NITSAN\NsT3AF\Service\BrandContextDashboardPresenter;
use NITSAN\NsT3AF\Service\DashboardAnalyticsService;
use NITSAN\NsT3AF\Service\DashboardChartConfigurator;
use NITSAN\NsT3AF\Service\DashboardModuleHealthService;
use NITSAN\NsT3AF\Service\DashboardPeriodComparisonService;
use NITSAN\NsT3AF\Service\DashboardPeriodResolver;
use NITSAN\NsT3AF\Service\DashboardProviderCardBuilder;
use NITSAN\NsT3AF\Service\DashboardViewModelBuilder;
use NITSAN\NsT3AF\Service\FeatureExtensionHealthService;
use NITSAN\NsT3AF\Service\ModuleStateService;
use NITSAN\NsT3AF\Service\SchedulerCliCommandCatalogService;
use NITSAN\NsT3AF\Service\SchedulerCliTaskService;
use NITSAN\NsT3AF\Service\SetupChecklistPresenter;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\WizardExtensionCatalogService;
use NITSAN\NsT3AF\Service\WizardProgressService;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRegistry;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Backend module controller for AI Foundation.
 *
 * Reference: ns_t3ai (NITSAN\NsT3Ai\Controller\T3AiBackendController). Modernized
 * to PSR-15 controller + ModuleTemplate (no Extbase).
 */
final class ModuleController extends AbstractAiUniverseModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        ModuleTabUtility $moduleTabUtility,
        ProviderRepositoryInterface $providerRepository,
        private readonly DashboardAnalyticsService $dashboardAnalytics,
        private readonly DashboardPeriodResolver $dashboardPeriodResolver,
        private readonly DashboardChartConfigurator $dashboardChartConfigurator,
        private readonly AiUsageAnalyticsService $aiUsageAnalyticsService,
        private readonly AiSysLogRepository $aiSysLogRepository,
        private readonly AiLogChannelCatalog $aiLogChannelCatalog,
        private readonly AiLogsStatisticsService $aiLogsStatisticsService,
        private readonly AiPromptsService $aiPromptsService,
        private readonly AiPromptsBrandContextInfoService $aiPromptsBrandContextInfoService,
        private readonly AiUniverseActivityLogService $activityLogService,
        private readonly RequestLogRepository $requestLogRepository,
        private readonly RecordAccessEnforcer $recordAccessEnforcer,
        private readonly SchedulerCliCommandCatalogService $schedulerCliCommandCatalog,
        private readonly SchedulerCliTaskService $schedulerCliTaskService,
        private readonly SetupChecklistPresenter $setupChecklistPresenter,
        private readonly McpDashboardOverviewPresenter $mcpDashboardOverviewPresenter,
        private readonly BrandContextDashboardPresenter $brandContextDashboardPresenter,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly CreditsDashboardService $creditsDashboardService,
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly CreditsCheckoutUrlValidator $checkoutUrlValidator,
        private readonly CreditsReturnUrlBuilder $creditsReturnUrlBuilder,
        PageRenderer $pageRenderer,
        CreditOverviewLineService $creditOverviewLine,
        private readonly McpServerStatusService $mcpServerStatusService,
        private readonly McpConnectionsService $mcpConnectionsService,
        private readonly TokenRepository $mcpTokenRepository,
        private readonly AdvancedSettingsService $mcpAdvancedSettingsService,
        private readonly WorkspaceListService $mcpWorkspaceListService,
        private readonly WorkspaceProvisionService $mcpWorkspaceProvisionService,
        private readonly WorkspacePreferenceService $mcpWorkspacePreferenceService,
        private readonly McpPathProvider $mcpPathProvider,
        private readonly McpPublicUrlService $mcpPublicUrlService,
        private readonly McpToolsRegistryService $mcpToolsRegistryService,
        private readonly McpSecurityService $mcpSecurityService,
        private readonly McpAnalyticsService $mcpAnalyticsService,
        private readonly McpHealthService $mcpHealthService,
        private readonly McpPromptTemplateService $mcpPromptTemplateService,
        private readonly McpCustomToolService $mcpCustomToolService,
        private readonly McpPlaygroundService $mcpPlaygroundService,
        private readonly McpSkillHubService $mcpSkillHubService,
        private readonly McpToolMetadataService $mcpToolMetadataService,
        private readonly DashboardPeriodComparisonService $dashboardPeriodComparison,
        private readonly DashboardModuleHealthService $dashboardModuleHealth,
        private readonly DashboardProviderCardBuilder $dashboardProviderCardBuilder,
        private readonly DashboardViewModelBuilder $dashboardViewModelBuilder,
        private readonly FeatureExtensionHealthService $featureExtensionHealthService,
        private readonly AiFeatureCardProviderRegistry $aiFeatureCardProviderRegistry,
        private readonly ExtensionSettingsRegistry $extensionSettingsRegistry,
        ModuleStateService $moduleStateService,
        WizardProviderCatalog $wizardProviderCatalog,
        WizardExtensionCatalogService $wizardExtensionCatalog,
        SiteStorageContext $siteStorageContext,
        WizardProgressService $wizardProgress,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $moduleTabUtility,
            $providerRepository,
            $pageRenderer,
            $creditOverviewLine,
            $moduleStateService,
            $wizardProviderCatalog,
            $wizardExtensionCatalog,
            $siteStorageContext,
            $wizardProgress,
        );
    }

    /**
     * Smart entry point: only ever triggered by the sidebar "Dashboard"
     * link. Redirects to the user's last-visited tab (or to the dedicated
     * overview route when the Dashboard itself was the last tab).
     *
     * Never renders a view — render lives in {@see overviewAction()}.
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $savedState = $backendUser !== null
            ? $this->moduleStateService->read($backendUser)
            : ModuleStateService::DEFAULTS;

        $targetRoute = $this->moduleTabUtility->routeFor($savedState['lastTab'])
            ?? 't3af_dashboard.overview';

        if ($backendUser !== null && !$backendUser->isAdmin()) {
            $targetRoute = $this->resolveAccessibleModuleRoute($backendUser, $targetRoute);
        }

        $routeParams = $this->routeParamsForPage($request);
        $redirectUri = (string) $this->uriBuilder->buildUriFromRoute($targetRoute, $routeParams);

        return new RedirectResponse($redirectUri);
    }

    /**
     * Renders the Dashboard tab. Used by the in-page Dashboard tab link
     * and any redirect from {@see indexAction()} when the saved last tab
     * is 'dashboard'.
     */
    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $queryParams = $request->getQueryParams();
        $hasExplicitPeriod = isset($queryParams['period']);

        $savedState = $backendUser !== null
            ? $this->moduleStateService->read($backendUser)
            : ModuleStateService::DEFAULTS;

        if (!$hasExplicitPeriod) {
            $restored = ['period' => $savedState['period']];
            if ($savedState['period'] === DashboardPeriodResolver::PRESET_CUSTOM) {
                if ($savedState['from'] !== '') {
                    $restored['from'] = $savedState['from'];
                }
                if ($savedState['to'] !== '') {
                    $restored['to'] = $savedState['to'];
                }
            }
            $request = $request->withQueryParams(array_replace($queryParams, $restored));
        }

        $view = $this->createModuleView($request, 'dashboard');
        $showFullOverview = $this->shouldShowFullDashboardOverview($backendUser);

        if (!$showFullOverview) {
            $allowedTabs = $backendUser !== null
                ? $this->moduleTabUtility->listVisibleNonDashboardTabs(
                    fn(string $key): string => $this->translateModule($key),
                    fn(string $route): string => (string) $this->uriBuilder->buildUriFromRoute(
                        $route,
                        $this->routeParamsForPage($request),
                    ),
                    $backendUser,
                )
                : [];

            $view->assignMultiple([
                'showFullDashboardOverview' => 0,
                'showDashboardAnalytics' => 0,
                'showAiContextOverview' => 0,
                'showMcpOverview' => 0,
                'showDashboardChecklist' => 0,
                'allowedDashboardTabs' => $allowedTabs,
            ]);

            return $view->renderResponse('Module/Dashboard');
        }

        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return $view->renderResponse('Module/PageSelectionRequired');
        }

        $storagePid = $resolution->storagePid;
        $routeParams = $this->routeParamsForPage($request);

        $this->setupChecklistPresenter->registerAssets($this->pageRenderer);
        $period = $this->dashboardPeriodResolver->resolve($request);

        if ($hasExplicitPeriod && $backendUser !== null) {
            $isCustom = $period['preset'] === DashboardPeriodResolver::PRESET_CUSTOM;
            $this->moduleStateService->setPeriod(
                $backendUser,
                $period['preset'],
                $isCustom ? date('Y-m-d', (int) $period['fromTimestamp']) : '',
                $isCustom ? date('Y-m-d', (int) $period['toTimestamp']) : '',
            );
        }

        $analyticsCredits = $this->dashboardAnalytics->forPeriod($period, RequestLogProviderScope::Credits);
        $analyticsOwnKeys = $this->dashboardAnalytics->forPeriod($period, RequestLogProviderScope::OwnKeys, $storagePid);

        $ownKeysProviders = [];
        $activeProviderCount = 0;
        $defaultIdentifier = null;
        foreach ($this->providerRepository->findAllByStoragePid($storagePid, includeHidden: true) as $provider) {
            if ($provider->identifier === CreditsProviderIdentifier::IDENTIFIER) {
                continue;
            }
            $ownKeysProviders[] = $provider;
            if ($provider->isEnabled) {
                $activeProviderCount++;
            }
            if ($provider->isDefault) {
                $defaultIdentifier = $provider->identifier;
            }
        }

        $creditsReturnUri = $this->creditsReturnUrlBuilder->fromRoute('t3af_dashboard.overview');
        $creditsDashboard = $this->creditsDashboardService->buildForProvidersPage($creditsReturnUri);
        $periodLabel = $period['preset'] === DashboardPeriodResolver::PRESET_CUSTOM
            ? $this->formatPeriodRangeLabel($period)
            : $this->translateModule($period['labelKey']);
        $creditsModeEnabled = $this->creditModeResolver->isEnabled();
        $activeAnalytics = $creditsModeEnabled ? $analyticsCredits : $analyticsOwnKeys;
        $hasRequestLogData = ((int) ($activeAnalytics['totals']['totalRequests'] ?? 0)) > 0;

        $aiUsageUri = $this->buildAiUsageUriFromDashboardPeriod($period);
        $mcpServerUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.mcp_server', $routeParams);
        $aiFeaturesUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_features', $routeParams);
        $aiPromptsUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts', $routeParams);
        $aiContextUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context', $routeParams);
        $schedulerCliUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.scheduler_cli', $routeParams);
        $providersUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers', $routeParams);

        $moduleHealth = $this->dashboardModuleHealth->build(
            $request,
            $analyticsOwnKeys['providerStats'] ?? [],
            [
                'providers' => $providersUri,
                'mcpServer' => $mcpServerUri,
                'aiFeatures' => $aiFeaturesUri,
                'aiPrompts' => $aiPromptsUri,
                'aiContext' => $aiContextUri,
                'schedulerCli' => $schedulerCliUri,
            ],
            $analyticsOwnKeys['scheduledTasks'] ?? [],
            $storagePid,
        );

        $ownKeysProviderUids = $this->dashboardAnalytics->resolveOwnKeysProviderUids($storagePid);
        $trendsCredits = $this->dashboardPeriodComparison->buildTrends(
            $period,
            $analyticsCredits['totals'] ?? [],
            RequestLogProviderScope::Credits,
            storagePid: $storagePid,
        );
        $trendsOwnKeys = $this->dashboardPeriodComparison->buildTrends(
            $period,
            $analyticsOwnKeys['totals'] ?? [],
            RequestLogProviderScope::OwnKeys,
            $activeProviderCount,
            $ownKeysProviderUids,
            $storagePid,
        );

        $creditProjection = $this->buildCreditProjection($creditsDashboard);
        $ownKeysSpendTotal = $this->sumProviderCosts($analyticsOwnKeys['providerStats'] ?? []);

        $activeProviderLabels = array_values(array_map(
            static fn($provider): string => $provider->title !== '' ? $provider->title : $provider->identifier,
            array_filter(
                $ownKeysProviders,
                static fn($provider): bool => $provider->isEnabled,
            ),
        ));
        $apiSpendSummary = $this->dashboardViewModelBuilder->buildApiSpendSummary($analyticsOwnKeys, $ownKeysSpendTotal);
        $creditsHero = $this->dashboardViewModelBuilder->buildCreditsHero($creditsDashboard, $analyticsCredits, $creditProjection);
        $kpiStripCredits = $this->dashboardViewModelBuilder->buildKpiStrip(
            $analyticsCredits,
            $trendsCredits,
            true,
            $creditsDashboard,
            $activeProviderLabels,
        );
        $kpiStripOwnKeys = $this->dashboardViewModelBuilder->buildKpiStrip(
            $analyticsOwnKeys,
            $trendsOwnKeys,
            false,
            $creditsDashboard,
            $activeProviderLabels,
        );
        $creditEfficiency = $this->dashboardViewModelBuilder->buildCreditEfficiency($analyticsCredits, $creditsDashboard);
        $providerDistributionLegend = $this->dashboardViewModelBuilder->buildProviderDistributionLegend(
            $analyticsOwnKeys['providerDistribution'] ?? [],
        );
        $recentRequestsCredits = $this->dashboardViewModelBuilder->enrichRecentRequests(
            $analyticsCredits['recentRequests'] ?? [],
        );
        $recentRequestsOwnKeys = $this->dashboardViewModelBuilder->enrichRecentRequests(
            $analyticsOwnKeys['recentRequests'] ?? [],
        );
        $extensionHealth = $this->featureExtensionHealthService->build(
            max(1, (int) ($period['days'] ?? 7)),
        );
        $moduleHealthSummary = $this->dashboardModuleHealth->summarize($moduleHealth);

        $checklistAssigns = $this->setupChecklistPresenter->buildAssigns(
            $request,
            $analyticsCredits,
            $analyticsOwnKeys,
            $storagePid,
            $creditsDashboard,
        );

        $showDashboardAnalytics = $backendUser !== null && $this->shouldShowDashboardAnalytics($backendUser);
        $showAiContextOverview = $backendUser !== null && $this->shouldShowAiContextOverview($backendUser);
        $showMcpOverview = $backendUser !== null && $this->shouldShowMcpOverview($backendUser);

        $mcpOverview = $showMcpOverview
            ? $this->mcpDashboardOverviewPresenter->build($request, '7d')
            : [];
        $aiContextOverview = $showAiContextOverview
            ? $this->brandContextDashboardPresenter->build($request, $aiContextUri)
            : ['siteResolved' => 0];

        $view->assignMultiple(array_merge(
            [
                'showFullDashboardOverview' => 1,
                'showDashboardAnalytics' => self::fluidFlag($showDashboardAnalytics),
                'showAiContextOverview' => self::fluidFlag($showAiContextOverview),
                'showMcpOverview' => self::fluidFlag($showMcpOverview),
                'showDashboardChecklist' => self::fluidFlag($showDashboardAnalytics),
                'allowedDashboardTabs' => [],
                'dashboardAnalyticsCredits' => $analyticsCredits,
                'dashboardAnalyticsOwnKeys' => $analyticsOwnKeys,
                'dashboardPeriod' => $period,
                'dashboardPeriodLabel' => $periodLabel,
                'providers' => $ownKeysProviders,
                'providerCards' => $this->enrichDashboardProviderCards(
                    $this->dashboardProviderCardBuilder->build(
                        $ownKeysProviders,
                        $analyticsOwnKeys['providerStats'] ?? [],
                    ),
                    $routeParams,
                    $backendUser,
                ),
                'activeProviderCount' => $activeProviderCount,
                'defaultIdentifier' => $defaultIdentifier ?? '',
                'creditsModeEnabled' => self::fluidFlag($creditsModeEnabled),
                'creditsModeActive' => self::fluidFlag($this->creditModeResolver->isActive()),
                'creditsFeatureAvailable' => self::fluidFlag($this->creditModeResolver->isPubliclyAvailable()),
                'creditsBearerToken' => $this->runtimeSettings->getTokenPlain() ?? '',
                'creditsDashboard' => $creditsDashboard,
                'creditProjection' => $creditProjection,
                'ownKeysSpendTotal' => $ownKeysSpendTotal,
                'ownKeysSpendTotalFormatted' => '$' . number_format($ownKeysSpendTotal, 2),
                'creditsPricingUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.credits_pricing'),
                'providersUri' => $providersUri,
                'aiUsageUri' => $aiUsageUri,
                'moduleHealth' => $moduleHealth,
                'trendsCredits' => $trendsCredits,
                'trendsOwnKeys' => $trendsOwnKeys,
                'dashboardPeriodPresets' => $this->buildDashboardPeriodPresets($period),
                'dashboardPeriodRangeLabel' => $this->formatPeriodRangeLabel($period),
                'dashboardPeriodFromDate' => date('Y-m-d', (int) $period['fromTimestamp']),
                'dashboardPeriodToDate' => date('Y-m-d', (int) $period['toTimestamp']),
                'hasRequestLogData' => $hasRequestLogData,
                'activityColumnMode' => $creditsModeEnabled ? 'credits' : 'cost',
                'creditsFooterCost' => number_format((float) ($analyticsCredits['totals']['totalCredits'] ?? 0.0), 1) . ' cr',
                'creditsFooterExtra' => (string) (int) ($creditsDashboard['stats']['estimatedDaysLeft'] ?? 0),
                'apiSpendSummary' => $apiSpendSummary,
                'creditsHero' => $creditsHero,
                'kpiStrip' => $creditsModeEnabled ? $kpiStripCredits : $kpiStripOwnKeys,
                'creditEfficiency' => $creditEfficiency,
                'providerDistributionLegend' => $providerDistributionLegend,
                'recentRequests' => $creditsModeEnabled ? $recentRequestsCredits : $recentRequestsOwnKeys,
                'activeAnalytics' => $activeAnalytics,
                'moduleHealthSummary' => $moduleHealthSummary,
                'extensionHealth' => $extensionHealth,
            ],
            $this->buildDashboardChartAssignments($analyticsCredits, $analyticsOwnKeys),
            $checklistAssigns,
            [
                'mcpOverview' => $mcpOverview,
                'aiContextOverview' => $aiContextOverview,
            ],
        ));

        return $view->renderResponse('Module/Dashboard');
    }

    public function providersAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTabPlaceholder($request, 'providers');
    }

    public function mcpServerAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'mcpServer');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/mcp-server.js');

        $beUser = $GLOBALS['BE_USER'] ?? null;
        $beUserUid = is_object($beUser) ? (int) ($beUser->user['uid'] ?? 0) : 0;

        $settings = $this->mcpAdvancedSettingsService->all();

        $siteRoot = rtrim($this->mcpPublicUrlService->resolveOrigin($request), '/');
        $status = $this->mcpServerStatusService->build($request);
        $serverUrl = (string) ($status['serverUrl'] ?? '');
        $basePath = $this->mcpPathProvider->getBasePath();
        $defaultScopes = (string) ($settings['oauthDefaultScopes'] ?? 'mcp:read mcp:write mcp:tools');
        $oauthAuthorizeUrl = $siteRoot . $basePath . '/oauth/authorize';
        $oauthTokenUrl = $siteRoot . $basePath . '/oauth/token';
        $wellKnownAuthUrl = (string) ($status['oauthEndpointUrls']['authorizationServer'] ?? ($siteRoot . (string) ($status['oauthEndpoints']['authorizationServer'] ?? '')));
        $wellKnownResourceUrl = (string) ($status['oauthEndpointUrls']['protectedResource'] ?? ($siteRoot . (string) ($status['oauthEndpoints']['protectedResource'] ?? '')));

        $clientTokens = $beUserUid > 0 ? $this->buildMcpClientTokenState($beUserUid) : ['n8n' => ['active' => 0], 'manus' => ['active' => 0]];
        $mcpRemoteState = $beUserUid > 0
            ? $this->buildMcpRemoteTokenState($beUserUid, $serverUrl)
            : ['active' => 0, 'uid' => 0, 'tokenUrl' => $serverUrl . '?token=YOUR_TOKEN', 'configJson' => ''];

        $mcpRemoteConfigJson = $mcpRemoteState['configJson'] !== ''
            ? htmlspecialchars((string) $mcpRemoteState['configJson'], ENT_NOQUOTES, 'UTF-8')
            : htmlspecialchars($this->buildMcpRemoteConfigJson('URL'), ENT_NOQUOTES, 'UTF-8');

        $accessLifetime = (int) ($settings['accessTokenLifetime'] ?? 86400);
        $refreshLifetime = (int) ($settings['refreshTokenLifetime'] ?? 2592000);
        $maxActiveTokens = (int) ($settings['oauthMaxActiveTokensPerUser'] ?? 10);
        $beUsername = is_object($beUser) ? (string) ($beUser->user['username'] ?? 'admin') : 'admin';
        $personalTokenState = $beUserUid > 0
            ? $this->buildMcpPersonalTokenState($beUserUid, $beUsername, $accessLifetime, $refreshLifetime, $maxActiveTokens)
            : ['active' => 0, 'uid' => 0, 'preview' => '', 'plain' => ''];

        $mcpWebSocketUrl = $this->buildMcpWebSocketUrl($serverUrl);
        $mcpNpxCommand = 'npx -y mcp-remote ' . $serverUrl;
        $mcpSshTunnelCommand = 'ssh -N -L 8787:127.0.0.1:443 user@your-server.example';
        $mcpDockerCommand = 'ddev exec vendor/bin/typo3 nst3af:mcp:serve --transport=stdio --user=' . $beUsername . ' --workspace=0';

        $snippetArgs = [
            'serverUrl' => $serverUrl,
            'oauthAuthorizeUrl' => $oauthAuthorizeUrl,
            'oauthTokenUrl' => $oauthTokenUrl,
            'scopes' => $defaultScopes,
            'clientTokens' => $clientTokens,
        ];

        $hasDraftWorkspace = $this->mcpWorkspaceProvisionService->hasDraftWorkspace();
        $canCreateWorkspace = $beUser instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
            && $this->mcpWorkspaceProvisionService->canUserCreateWorkspaces($beUser);

        $universeConf = AiUniverseUtilityHelper::getExtensionConfIgnorePid('ns_t3af');
        $mcpMode = strtolower(trim((string) ($universeConf['mcpMode'] ?? 'context')));
        if (!in_array($mcpMode, ['context', 'native'], true)) {
            $mcpMode = 'context';
        }

        $view->assignMultiple([
            'mcpStatus' => $status,
            'mcpConnections' => $this->mcpConnectionsService->listActive(),
            'mcpAdvancedSettings' => $settings,
            'mcpSecuritySettings' => $this->mcpSecurityService->allSecuritySettings(),
            'mcpMtlsFeatureAvailable' => $this->mcpSecurityService->isMtlsFeatureAvailable(),
            'mcpAnalyticsPeriod' => '7d',
            'mcpAnalyticsSummary' => $this->mcpAnalyticsService->getSummary('7d'),
            'mcpAnalyticsChart' => $this->normalizeAnalyticsChart($this->mcpAnalyticsService->getDailyChart('7d')),
            'mcpAnalyticsTopTools' => $this->mcpAnalyticsService->getTopTools(10, '7d'),
            'mcpAnalyticsErrors' => $this->mcpAnalyticsService->getErrors('7d', 20),
            'mcpAnalyticsRateLimits' => $this->mcpAnalyticsService->getRateLimits('7d'),
            'mcpConnectionHealth' => $this->mcpHealthService->listConnectionHealth($request),
            'mcpTransports' => $this->buildMcpTransportCards(),
            'mcpTransportGuides' => $this->buildMcpTransportClientGuides(),
            'mcpWorkspaces' => $this->mcpWorkspaceListService->list(),
            'mcpSelectedWorkspaceId' => $this->mcpWorkspacePreferenceService->getForCurrentUser(),
            'mcpMode' => $mcpMode,
            'mcpNeedsWorkspace' => !$hasDraftWorkspace ? 1 : 0,
            'mcpCanCreateWorkspace' => $canCreateWorkspace ? 1 : 0,
            'mcpBasePath' => $basePath,
            'mcpServerUrl' => $serverUrl,
            'mcpOAuthAuthorizeUrl' => $oauthAuthorizeUrl,
            'mcpOAuthTokenUrl' => $oauthTokenUrl,
            'mcpWellKnownAuthUrl' => $wellKnownAuthUrl,
            'mcpWellKnownResourceUrl' => $wellKnownResourceUrl,
            'mcpDefaultScopes' => $defaultScopes,
            'mcpSnippetArgs' => $snippetArgs,
            'mcpClientTokens' => $clientTokens,
            'mcpRemoteTokenActive' => (int) ($mcpRemoteState['active'] ?? 0),
            'mcpRemoteTokenUid' => (int) ($mcpRemoteState['uid'] ?? 0),
            'mcpRemoteTokenUrl' => (string) ($mcpRemoteState['tokenUrl'] ?? ($serverUrl . '?token=YOUR_TOKEN')),
            'mcpRemoteConfigJson' => $mcpRemoteConfigJson,
            'mcpCliConfigJson' => htmlspecialchars($this->buildCliConfigJson(), ENT_NOQUOTES, 'UTF-8'),
            'mcpPersonalTokenActive' => (int) ($personalTokenState['active'] ?? 0),
            'mcpPersonalTokenUid' => (int) ($personalTokenState['uid'] ?? 0),
            'mcpPersonalTokenPreview' => (string) ($personalTokenState['preview'] ?? ''),
            'mcpPersonalTokenPlain' => (string) ($personalTokenState['plain'] ?? ''),
            'mcpWebSocketUrl' => $mcpWebSocketUrl,
            'mcpNpxCommand' => htmlspecialchars($mcpNpxCommand, ENT_NOQUOTES, 'UTF-8'),
            'mcpSshTunnelCommand' => htmlspecialchars($mcpSshTunnelCommand, ENT_NOQUOTES, 'UTF-8'),
            'mcpDockerCommand' => htmlspecialchars($mcpDockerCommand, ENT_NOQUOTES, 'UTF-8'),
        ]);

        return $view->renderResponse('Module/McpServer');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildMcpClientTokenState(int $beUserUid): array
    {
        return [
            'n8n' => $this->formatMcpClientTokenState($beUserUid, TokenRepository::LABEL_N8N, 'n8n'),
            'manus' => $this->formatMcpClientTokenState($beUserUid, TokenRepository::LABEL_MANUS, 'manus'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMcpClientTokenState(int $beUserUid, string $label, string $clientKey): array
    {
        $token = $this->mcpTokenRepository->findActiveByLabel($beUserUid, $label);

        return [
            'clientKey' => $clientKey,
            'label' => $label,
            'active' => $token !== null ? 1 : 0,
            'preview' => $token?->preview() ?? '',
            'uid' => $token !== null ? $token->uid : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMcpRemoteTokenState(int $beUserUid, string $serverUrl): array
    {
        $token = $this->mcpTokenRepository->findActiveByLabel($beUserUid, TokenRepository::LABEL_MCP_REMOTE);
        $placeholderUrl = $serverUrl . '?token=YOUR_TOKEN';

        if ($token === null) {
            return [
                'active' => 0,
                'uid' => 0,
                'tokenUrl' => $placeholderUrl,
                'configJson' => $this->buildMcpRemoteConfigJson('URL'),
            ];
        }

        return [
            'active' => 1,
            'uid' => $token->uid,
            'tokenUrl' => $placeholderUrl,
            'configJson' => $this->buildMcpRemoteConfigJson('URL'),
        ];
    }

    private function buildMcpRemoteConfigJson(string $url): string
    {
        return json_encode([
            'mcpServers' => [
                'New TYPO3 site' => [
                    'command' => 'npx',
                    'args' => ['mcp-remote', $url],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildCliConfigJson(): string
    {
        return json_encode([
            'mcpServers' => [
                'New TYPO3 site' => [
                    'command' => 'php',
                    'args' => ['vendor/bin/typo3', 'mcp:server'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array{active: int, uid: int, preview: string, plain: string}
     */
    private function buildMcpPersonalTokenState(
        int $beUserUid,
        string $username,
        int $accessLifetime,
        int $refreshLifetime,
        int $maxActiveTokens,
    ): array {
        $plain = '';
        try {
            $issued = $this->mcpTokenRepository->ensurePersonalBearerToken(
                $beUserUid,
                $username,
                $accessLifetime,
                $refreshLifetime,
                $maxActiveTokens,
            );
            if ($issued !== null) {
                $plain = $issued;
            }
        } catch (\RuntimeException) {
            // Max tokens reached — fall through to preview-only state.
        }

        $token = $this->mcpTokenRepository->findPersonalBearerToken($beUserUid);

        return [
            'active' => $token !== null ? 1 : 0,
            'uid' => (int) ($token['uid'] ?? 0),
            'preview' => (string) ($token['preview'] ?? ''),
            'plain' => $plain,
        ];
    }

    private function buildMcpWebSocketUrl(string $serverUrl): string
    {
        if ($serverUrl === '') {
            return 'wss://your-host/mcp';
        }

        return (string) preg_replace('#^https?://#i', 'wss://', $serverUrl);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildMcpTransportCards(): array
    {
        return [
            ['key' => 'http', 'icon' => 'actions-globe', 'available' => 1, 'badge' => 'recommended'],
            ['key' => 'websocket', 'icon' => 'actions-exchange', 'available' => 1, 'badge' => 'realTime'],
            ['key' => 'docker', 'icon' => 'actions-extension', 'available' => 1, 'badge' => ''],
            ['key' => 'sshtunnel', 'icon' => 'actions-lock', 'available' => 1, 'badge' => 'enterprise'],
            ['key' => 'npx', 'icon' => 'actions-code', 'available' => 1, 'badge' => ''],
            ['key' => 'mcpremote', 'icon' => 'actions-link', 'available' => 1, 'badge' => 'advanced'],
            ['key' => 'stdio', 'icon' => 'actions-terminal', 'available' => 1, 'badge' => 'devOnly'],
        ];
    }

    /**
     * @return list<array{key: string, icon: string, snippet: string}>
     */
    private function buildMcpClientCards(): array
    {
        return [
            ['key' => 'claude', 'icon' => 'actions-chat', 'snippet' => 'ClaudeDesktop'],
            ['key' => 'cursor', 'icon' => 'actions-open', 'snippet' => 'Cursor'],
            ['key' => 'vscode', 'icon' => 'actions-code', 'snippet' => 'VsCode'],
            ['key' => 'windsurf', 'icon' => 'actions-eye', 'snippet' => 'Windsurf'],
            ['key' => 'continue', 'icon' => 'actions-play', 'snippet' => 'Continue'],
            ['key' => 'zed', 'icon' => 'actions-document', 'snippet' => 'Zed'],
            ['key' => 'jetbrains', 'icon' => 'actions-extension', 'snippet' => 'JetBrains'],
            ['key' => 'n8n', 'icon' => 'actions-cog', 'snippet' => 'N8n'],
            ['key' => 'manus', 'icon' => 'actions-user', 'snippet' => 'Manus'],
            ['key' => 'dify', 'icon' => 'actions-globe', 'snippet' => 'Other'],
            ['key' => 'langchain', 'icon' => 'actions-link', 'snippet' => 'CustomJson'],
            ['key' => 'autogen', 'icon' => 'actions-lightbulb', 'snippet' => 'CustomJson'],
            ['key' => 'codex', 'icon' => 'actions-lightbulb', 'snippet' => 'ChatGpt'],
            ['key' => 'openai-gpts', 'icon' => 'actions-chat', 'snippet' => 'ChatGpt'],
            ['key' => 'copilot-studio', 'icon' => 'actions-window', 'snippet' => 'Other'],
            ['key' => 'inspector', 'icon' => 'actions-search', 'snippet' => 'Inspector'],
            ['key' => 'other', 'icon' => 'actions-menu-alternative', 'snippet' => 'Other'],
        ];
    }

    /**
     * @return array<string, list<array{key: string, icon: string, snippet: string}>>
     */
    private function buildMcpTransportClientGuides(): array
    {
        $catalog = [];
        foreach ($this->buildMcpClientCards() as $client) {
            $catalog[$client['key']] = $client;
        }

        /** @var array<string, list<string>> $transportClientKeys */
        $transportClientKeys = [
            'http' => ['claude', 'cursor', 'vscode', 'windsurf', 'continue', 'jetbrains', 'n8n', 'manus', 'dify', 'langchain', 'autogen', 'codex', 'openai-gpts', 'copilot-studio', 'inspector', 'other'],
            'websocket' => ['zed', 'continue', 'cursor', 'langchain', 'other'],
            'docker' => ['claude', 'cursor', 'vscode', 'windsurf', 'continue', 'zed', 'jetbrains', 'other'],
            'sshtunnel' => ['claude', 'cursor', 'vscode', 'continue', 'other'],
            'npx' => ['claude', 'cursor', 'vscode', 'windsurf', 'continue', 'langchain', 'autogen', 'other'],
            'mcpremote' => ['claude', 'cursor', 'vscode', 'other'],
            'stdio' => ['claude', 'cursor', 'vscode', 'windsurf', 'continue', 'zed', 'jetbrains', 'inspector', 'other'],
        ];

        $guides = [];
        foreach ($transportClientKeys as $transport => $keys) {
            $guides[$transport] = array_values(array_filter(array_map(
                static fn(string $key): ?array => $catalog[$key] ?? null,
                $keys,
            )));
        }

        return $guides;
    }

    /**
     * @param list<array{day: string, calls: int, success: int, errors: int}> $chart
     * @return list<array{day: string, calls: int, success: int, errors: int, barHeight: int}>
     */
    private function normalizeAnalyticsChart(array $chart): array
    {
        if ($chart === []) {
            return [];
        }

        $maxCalls = max(1, ...array_map(static fn(array $row): int => (int) ($row['calls'] ?? 0), $chart));

        return array_map(static function (array $row) use ($maxCalls): array {
            $calls = (int) ($row['calls'] ?? 0);

            return array_merge($row, [
                'barHeight' => (int) round(($calls / $maxCalls) * 100),
            ]);
        }, $chart);
    }

    public function mcpToolsAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'mcpTools');

        $queryParams = $request->getQueryParams();
        $periodResolved = $this->dashboardPeriodResolver->resolveFromQueryParams(
            $queryParams,
            DashboardPeriodResolver::PRESET_7D,
        );

        $allExtensions = $this->mcpToolsRegistryService->getAiUniverseExtensions($queryParams);
        $coreExtension = null;
        $aiExtensions = [];
        foreach ($allExtensions as $extension) {
            if (($extension['id'] ?? '') === 'ns_t3af_core') {
                $coreExtension = $extension;
            } else {
                $aiExtensions[] = $extension;
            }
        }

        $allTools = $this->mcpToolsRegistryService->getAllTools();
        $statistics = $this->mcpToolsRegistryService->getStatisticsForTools($allTools, $queryParams);
        $playgroundConfig = $this->mcpPlaygroundService->buildConfig();

        $view->assignMultiple([
            'mcpToolsPeriod' => $periodResolved['preset'],
            'mcpToolsPeriodFromDate' => date('Y-m-d', (int) $periodResolved['fromTimestamp']),
            'mcpToolsPeriodToDate' => date('Y-m-d', (int) $periodResolved['toTimestamp']),
            'mcpToolsPeriodLabel' => $this->formatPeriodLabel($periodResolved),
            'mcpToolsPeriodPresets' => $this->buildPeriodPresets(
                't3af_dashboard.mcp_tools',
                $periodResolved,
            ),
            'mcpToolsPeriodFormAction' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.mcp_tools'),
            'mcpToolsUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.mcp_tools'),
            'aiExtensions' => $aiExtensions,
            'coreExtension' => $coreExtension,
            'coreToolCount' => (int) ($coreExtension['toolCount'] ?? 0),
            'customTables' => $this->mcpToolsRegistryService->getCustomTableRows(),
            'mcpToolsStats' => $statistics,
            'mcpToolsExtConfSnippet' => $this->mcpToolsRegistryService->buildExtConfSnippet(),
            'mcpPromptTemplates' => $this->mcpPromptTemplateService->listTemplates(),
            'mcpCustomTools' => $this->decorateCustomToolsForView($this->mcpCustomToolService->listTools()),
            'mcpToolCategories' => $this->mcpToolMetadataService->getCategories(),
            'mcpPlaygroundConfig' => $playgroundConfig,
            'mcpPlaygroundConfigJson' => json_encode(
                $playgroundConfig,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
            ) ?: '{}',
            'mcpSkillCatalog' => $this->mcpSkillHubService->getCommunityCatalog(),
            'mcpInstalledSkills' => $this->mcpSkillHubService->getInstalledSkills(),
        ]);

        return $view->renderResponse('Module/McpTools');
    }

    /**
     * Adds a JSON-encoded parameters payload to each custom tool row so the backend edit form
     * can rebuild the parameter table client-side.
     *
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private function decorateCustomToolsForView(array $tools): array
    {
        return array_map(static function (array $tool): array {
            $tool['parametersJson'] = json_encode(
                $tool['parameters'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '[]';

            return $tool;
        }, $tools);
    }

    public function mcpConnectorsAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTabPlaceholder($request, 'mcpConnectors');
    }

    public function aiFeaturesAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiFeatures');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/ai-features.js');

        if (!$this->resolveSiteStorage($request)->isResolved()) {
            return $view->renderResponse('Module/PageSelectionRequired');
        }

        $backendUser = $this->getBackendUser();
        $features = $this->localizeAiFeaturesCatalogDescriptions(
            $this->aiFeatureCardProviderRegistry->buildCatalog($backendUser),
        );
        $features = array_map(
            fn(array $feature): array => $this->enrichAiFeaturesCardWithExtensionTitle($feature),
            $features,
        );
        $extensionKeys = $this->aiFeatureCardProviderRegistry->collectFilterExtensionKeys();
        sort($extensionKeys);

        $managedExtensionSettingsKeys = $this->extensionSettingsRegistry->getManagedExtensionKeys();
        sort($managedExtensionSettingsKeys);

        $canModifyExtensionSettings = $backendUser === null || $this->recordAccessEnforcer->canModifyCatalogId(
            $backendUser,
            AiUniverseRecordMap::EXTENSION_SETTINGS,
        );

        $view->assignMultiple([
            'aiFeaturesCards' => $features,
            'aiFeaturesCardsJson' => json_encode(
                $features,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
            ) ?: '[]',
            'aiFeaturesExtensions' => $this->buildExtensionTitleOptions($extensionKeys),
            'aiFeaturesManagedExtensionKeysJson' => json_encode(
                $managedExtensionSettingsKeys,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
            ) ?: '[]',
            'canModifyExtensionSettings' => self::fluidFlag($canModifyExtensionSettings),
            'aiFeaturesSyncUri' => (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.ai_features',
                $this->routeParamsForPage($request),
            ),
            'aiFeaturesSettingsGetUri' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nst3af_feature_settings_get'),
            'aiFeaturesSettingsSaveUri' => (string) $this->uriBuilder->buildUriFromRoute('ajax_nst3af_feature_settings_save'),
        ]);

        return $view->renderResponse('Module/AiFeatures');
    }

    public function aiUsageAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiUsage');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/ai-usage.js');

        $params = $request->getQueryParams();
        $body = $request->getParsedBody() ?? [];
        $query = array_merge($params, is_array($body) ? $body : []);
        $periodResolved = $this->dashboardPeriodResolver->resolveFromQueryParams(
            $query,
            DashboardPeriodResolver::PRESET_7D,
        );
        $period = $this->aiUsageAnalyticsService->mapResolvedPeriod($periodResolved);
        $period['label'] = $this->formatPeriodLabel($periodResolved);
        $filters = $this->aiUsageAnalyticsService->normalizeFilters($query);
        $mode = $this->resolveAiUsageMode($query);
        $usageData = $this->aiUsageAnalyticsService->buildUsageData($period, $filters);
        $routeContext = $this->buildAiUsageRouteContext($query, $mode, $filters);

        $logPage = $mode === 'log'
            ? $this->aiUsageAnalyticsService->buildLogPage($period, $filters)
            : null;
        $filtersForView = $filters;
        $aiUsagePagination = null;
        if ($logPage !== null) {
            $filtersForView['currentPage'] = $logPage['currentPage'];
            $paginator = new FixedTotalPaginator(
                $logPage['totalCount'],
                $logPage['entries'],
                $logPage['currentPage'],
                $logPage['perPage'],
            );
            $aiUsagePagination = [
                'pagination' => new SimplePagination($paginator),
                'paginator' => $paginator,
            ];
        }

        $view->assignMultiple([
            'flash' => $this->flashFromQuery($request) ?? '',
            'aiUsageMode' => $mode,
            'aiUsagePeriod' => $period,
            'aiUsagePeriodLabel' => $period['label'],
            'aiUsagePeriodPresets' => $this->buildPeriodPresets(
                't3af_dashboard.ai_usage',
                $periodResolved,
                $routeContext,
            ),
            'aiUsagePeriodFromDate' => date('Y-m-d', (int) $periodResolved['fromTimestamp']),
            'aiUsagePeriodToDate' => date('Y-m-d', (int) $periodResolved['toTimestamp']),
            'aiUsagePeriodFormAction' => (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.ai_usage',
                $routeContext,
            ),
            'aiUsageFilters' => $filtersForView,
            'aiUsageFilterOptions' => $usageData['filterOptions'],
            'aiUsageEntries' => $logPage['entries'] ?? [],
            'aiUsageTotalCount' => $logPage['totalCount'] ?? 0,
            'aiUsagePagination' => $aiUsagePagination,
            'aiUsageSummary' => $usageData['summary'],
            'aiUsageKpis' => $usageData['kpis'],
            'aiUsageListUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_usage'),
            'aiUsageDeleteUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_usage.delete'),
            'aiUsageBulkDeleteUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_usage.bulk_delete'),
            'aiUsageExportUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_usage.export'),
        ]);

        return $view->renderResponse('Module/AiUsage');
    }

    public function aiUsageDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyUsageLog($request)) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $uid = (int) (is_array($body) ? ($body['uid'] ?? 0) : 0);
        $deleted = 0;
        if ($uid > 0) {
            $deleted = $this->requestLogRepository->softDeleteByUid($uid);
        }

        return $this->redirectToAiUsage($request, $deleted > 0 ? 'deleted' : 'not-deleted');
    }

    public function aiUsageBulkDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyUsageLog($request)) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $raw = is_array($body) ? ($body['uids'] ?? []) : [];
        $uids = is_array($raw) ? array_values(array_map('intval', $raw)) : [];
        $deleted = $this->requestLogRepository->softDeleteByUids($uids);

        return $this->redirectToAiUsage($request, $deleted > 0 ? 'bulk-deleted' : 'none-selected');
    }

    public function aiUsageExportAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyUsageLog($request)) {
            return $denied;
        }
        $query = $request->getQueryParams();
        $period = $this->aiUsageAnalyticsService->resolvePeriod($query);
        $filters = $this->aiUsageAnalyticsService->normalizeFilters($query);
        $format = strtolower(trim((string) ($query['format'] ?? 'json')));

        if ($format === 'csv') {
            $rows = $this->aiUsageAnalyticsService->buildLogCsvRows($period, $filters);
            $lines = array_map(
                static fn(array $row): string => implode(',', array_map(
                    static fn(string $cell): string => '"' . str_replace('"', '""', $cell) . '"',
                    $row,
                )),
                $rows,
            );
            $response = new HtmlResponse(implode("\n", $lines));

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="ai-usage-' . date('Ymd-His') . '.csv"');
        }

        $payload = $this->aiUsageAnalyticsService->buildExportPayload($period, $filters);

        $filename = sprintf('ai-usage-%s.json', date('Ymd-His'));
        $response = new JsonResponse($payload);
        return $response
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function aiLogsAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiLogs');

        $query = array_merge(
            $request->getQueryParams(),
            is_array($request->getParsedBody()) ? $request->getParsedBody() : [],
        );
        $periodResolved = $this->dashboardPeriodResolver->resolveFromQueryParams(
            $query,
            DashboardPeriodResolver::PRESET_7D,
        );
        $filters = $this->normalizeAiLogsFilters($query, $periodResolved);
        $routeContext = $this->buildAiLogsRouteContext($filters);
        $perPage = (int) ($filters['max'] ?? 50);
        $currentPage = max(1, (int) ($filters['currentPage'] ?? 1));

        $totalCount = $this->aiSysLogRepository->countFiltered($filters);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $logs = $this->aiSysLogRepository->findFiltered($filters, $perPage, $offset);

        $paginator = new FixedTotalPaginator($totalCount, $logs, $currentPage, $perPage);
        $pagination = new SimplePagination($paginator);

        $view->assignMultiple([
            'flash' => $this->flashFromQuery($request) ?? '',
            'aiLogsEntries' => $logs,
            'aiLogsFilters' => array_merge($filters, [
                'max' => $perPage,
                'currentPage' => $currentPage,
            ]),
            'aiLogsTotalCount' => $totalCount,
            'aiLogsStats' => $this->aiLogsStatisticsService->buildSummary($filters),
            'aiLogsSchedulerCliUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.scheduler_cli'),
            'aiLogsPagination' => [
                'pagination' => $pagination,
                'paginator' => $paginator,
            ],
            'aiLogsLogChannelOptions' => $this->buildAiLogsLogChannelOptions(),
            'aiLogsExtensionOptions' => $this->buildAiLogsExtensionOptions(),
            'aiLogsRouteContext' => $routeContext,
            'aiLogsPeriodLabel' => $this->formatPeriodLabel($periodResolved),
            'aiLogsPeriodPresets' => $this->buildPeriodPresets(
                't3af_dashboard.ai_logs',
                $periodResolved,
                $routeContext,
            ),
            'aiLogsPeriodFromDate' => date('Y-m-d', (int) $periodResolved['fromTimestamp']),
            'aiLogsPeriodToDate' => date('Y-m-d', (int) $periodResolved['toTimestamp']),
            'aiLogsPeriodFormAction' => (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.ai_logs',
                $routeContext,
            ),
            'aiLogsListUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_logs'),
        ]);

        return $view->renderResponse('Module/AiLogs');
    }

    public function aiLogsExportAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $periodResolved = $this->dashboardPeriodResolver->resolveFromQueryParams(
            $query,
            DashboardPeriodResolver::PRESET_7D,
        );
        $filters = $this->normalizeAiLogsFilters($query, $periodResolved);
        $rows = $this->aiSysLogRepository->findForExport($filters);

        $lines = ['Time,Level,User,Details'];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '"%s","%s","%s","%s"',
                date('Y-m-d H:i:s', (int) ($row['tstamp'] ?? 0)),
                str_replace('"', '""', (string) ($row['level'] ?? '')),
                (string) ($row['userid'] ?? 0),
                str_replace('"', '""', (string) ($row['details'] ?? '')),
            );
        }

        $response = new HtmlResponse(implode("\n", $lines));
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="ai-logs-' . date('Ymd-His') . '.csv"');
    }

    public function aiLogsDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $raw = is_array($body) ? ($body['uids'] ?? []) : [];
        $uids = is_array($raw) ? array_values(array_map('intval', $raw)) : [];
        if ($uids === [] && is_array($body) && isset($body['uid'])) {
            $uids = [(int) $body['uid']];
        }
        $deleted = $this->aiSysLogRepository->deleteByUids($uids);

        return $this->redirectToAiLogs(
            $request,
            $deleted > 0 ? ($uids !== [] && count($uids) > 1 ? 'bulk-deleted' : 'deleted') : 'none-selected',
        );
    }

    public function aiPromptsAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiPrompts');
        $resolution = $this->resolveSiteStorage($request);

        if (!$resolution->isResolved()) {
            return $view->renderResponse('Module/PageSelectionRequired');
        }

        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/ai-prompts.js');

        $params = $request->getQueryParams();
        $body = $request->getParsedBody() ?? [];
        $query = array_merge($params, is_array($body) ? $body : []);
        $routeParams = $this->routeParamsForPage($request);
        $storagePid = $resolution->storagePid;
        $mode = $this->resolveAiPromptsMode($query);
        $category = trim((string) ($query['category'] ?? 'seo'));
        $filters = $this->normalizeAiPromptsFilters($query);
        // Detail row filters have no UI; ignore query params (e.g. promptType/scope leaked from create form redirects).
        $filters['title'] = '';
        $filters['text'] = '';
        $filters['promptType'] = 'all';
        $filters['scope'] = 'all';
        $overview = $this->aiPromptsService->buildOverviewData($filters, $storagePid);
        $overview['extensions'] = $this->buildExtensionTitleOptions(
            array_column($overview['extensions'] ?? [], 'key'),
        );
        $overview['categories'] = array_map(
            fn(array $category): array => $this->enrichAiPromptsCategoryWithExtensionTitle($category),
            $overview['categories'] ?? [],
        );
        $detail = $this->aiPromptsService->buildCategoryDetail($category, $filters, $storagePid);
        $detail['category'] = $this->enrichAiPromptsCategoryWithExtensionTitle($detail['category'] ?? []);
        $validationError = trim((string) ($query['validationError'] ?? ''));
        $validationPromptType = trim((string) ($query['validationPromptType'] ?? ''));

        $view->assignMultiple([
            'flash' => $this->flashFromQuery($request) ?? '',
            'currentPageId' => $resolution->pageId,
            'aiPromptsStoragePid' => $storagePid,
            'aiPromptsMode' => $mode,
            'aiPromptsCategory' => $category,
            'aiPromptsFilters' => $filters,
            'aiPromptsOverview' => $overview,
            'aiPromptsDetail' => $detail,
            'aiPromptsListUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts', $routeParams),
            'aiPromptsSyncUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts.sync', $routeParams),
            'aiPromptsCreateUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts.create', $routeParams),
            'aiPromptsUpdateUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts.update', $routeParams),
            'aiPromptsDeleteUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts.delete', $routeParams),
            'aiPromptsCatalogJson' => json_encode(
                $this->aiPromptsService->getCatalogForUi(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
            ) ?: '{}',
            'aiPromptsRowsJson' => json_encode(
                $detail['rows'] ?? [],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
            ) ?: '[]',
            'aiPromptsValidationError' => $validationError,
            'aiPromptsValidationPromptType' => $validationPromptType,
            'aiPromptsValidationMessagesJson' => json_encode([
                'missing_scope_type' => $this->translateDashboard('module.aiPrompts.validation.missingScopeType'),
                'invalid_prompt_type' => $this->translateDashboard('module.aiPrompts.validation.invalidPromptType'),
                'scope_mismatch' => $this->translateDashboard('module.aiPrompts.validation.scopeMismatch'),
                'missing_required_variables' => $this->translateDashboard('module.aiPrompts.validation.missingVariables'),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '{}',
            'aiPromptsBrandContextJson' => json_encode(
                $this->aiPromptsBrandContextInfoService->buildByCategory($resolution->pageId),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
            ) ?: '{}',
            'brandContextPlaceholders' => \NITSAN\NsT3AF\Service\BrandContextService::PLACEHOLDERS,
        ]);

        return $view->renderResponse('Module/AiPrompts');
    }

    public function aiPromptsSyncAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyGlobalPrompts($request)) {
            return $denied;
        }
        $result = $this->aiPromptsService->synchronizeDefaults($this->resolveSiteStorage($request)->storagePid);
        $created = (int) ($result['created'] ?? 0);
        $this->activityLogService->logPromptSync($created);
        $flash = $created > 0 ? 'prompts-synced' : 'prompts-already-synced';

        return $this->redirectToAiPrompts($request, $flash);
    }

    public function aiPromptsCreateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyGlobalPrompts($request)) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $resolution = $this->resolveSiteStorage($request);
        $payload['storagePid'] = $resolution->storagePid;
        $category = trim((string) ($payload['category'] ?? 'seo'));
        if (($validationError = $this->aiPromptsService->validateGlobalPromptPayload($category, $payload)) !== null) {
            return $this->redirectToAiPrompts(
                $request,
                'prompt-validation-fail',
                $category,
                'detail',
                $validationError,
                trim((string) ($payload['promptType'] ?? '')),
            );
        }
        $ok = $this->aiPromptsService->createPrompt($category, $payload);
        if ($ok) {
            $title = trim((string) ($payload['promptTitle'] ?? ''));
            $this->activityLogService->logPromptCreated($category, $title !== '' ? $title : 'Untitled');
        }

        return $this->redirectToAiPrompts($request, $ok ? 'prompt-created' : 'prompt-create-fail', $category, 'detail');
    }

    public function aiPromptsUpdateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyGlobalPrompts($request)) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $resolution = $this->resolveSiteStorage($request);
        $payload['storagePid'] = $resolution->storagePid;
        $category = trim((string) ($payload['category'] ?? 'seo'));
        $uid = (int) ($payload['uid'] ?? 0);
        if (($validationError = $this->aiPromptsService->validateGlobalPromptPayload($category, $payload)) !== null) {
            return $this->redirectToAiPrompts(
                $request,
                'prompt-validation-fail',
                $category,
                'detail',
                $validationError,
                trim((string) ($payload['promptType'] ?? '')),
            );
        }
        $ok = $this->aiPromptsService->updatePrompt($category, $uid, $payload);
        if ($ok) {
            $title = trim((string) ($payload['promptTitle'] ?? ''));
            $this->activityLogService->logPromptUpdated($category, $uid, $title !== '' ? $title : 'Untitled');
        }

        return $this->redirectToAiPrompts($request, $ok ? 'prompt-updated' : 'prompt-update-fail', $category, 'detail');
    }

    public function aiPromptsDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyGlobalPrompts($request)) {
            return $denied;
        }
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $category = trim((string) ($payload['category'] ?? 'seo'));
        $uid = (int) ($payload['uid'] ?? 0);
        $storagePid = $this->resolveSiteStorage($request)->storagePid;
        $ok = $this->aiPromptsService->deletePrompt($category, $uid, $storagePid);
        if ($ok) {
            $this->activityLogService->logPromptDeleted($category, $uid);
        }

        return $this->redirectToAiPrompts($request, $ok ? 'prompt-deleted' : 'prompt-delete-fail', $category, 'detail');
    }

    public function forDevelopersAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'forDevelopers');
        $this->pageRenderer->addCssFile('EXT:ns_t3af/Resources/Public/Css/for-developers.css');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/for-developers.js');

        return $view->renderResponse('Backend/ForDevelopers');
    }

    public function schedulerCliAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'schedulerCli');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/scheduler-cli.js');
        $query = $request->getQueryParams();
        $mode = $this->resolveSchedulerCliMode($query);
        $commands = $this->schedulerCliCommandCatalog->all();
        $extensionGroups = $this->schedulerCliCommandCatalog->extensionGroups();
        $tasks = $this->schedulerCliTaskService->listTasks(['status' => 'all']);
        $extensions = [];
        foreach ($extensionGroups as $group) {
            $extension = trim((string) ($group['id'] ?? ''));
            if ($extension !== '' && !in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }
        sort($extensions);

        $typo3Version = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Information\Typo3Version::class);
        $routeName = $typo3Version->getMajorVersion() >= 14 ? 'scheduler' : 'scheduler_manage';
        $schedulerManageUri = (string) $this->uriBuilder->buildUriFromRoute($routeName);
        $schedulerManageAddUri = (string) $this->uriBuilder->buildUriFromRoute($routeName, ['action' => 'add']);

        $view->assignMultiple([
            'flash' => $this->flashFromQuery($request) ?? '',
            'schedulerCliRunOutput' => (string) ($query['runOutput'] ?? ''),
            'schedulerCliMode' => $mode,
            'schedulerCliExtensions' => $extensions,
            'schedulerCliExtensionGroups' => $extensionGroups,
            'schedulerCliCommands' => $commands,
            'schedulerCliTasks' => $tasks,
            'routeName' => $routeName,
            'schedulerCliKpis' => [
                'totalCommands' => count($commands),
                'totalTasks' => count($tasks),
                'activeTasks' => count(array_filter($tasks, static fn(array $task): bool => ((int) ($task['disabled'] ?? 1)) === 0)),
                'failingTasks' => count(array_filter($tasks, static fn(array $task): bool => ((int) ($task['hasFailure'] ?? 0)) === 1)),
            ],
            'schedulerCliRunUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.scheduler_cli.run'),
            'schedulerManageUri' => $schedulerManageUri,
            'schedulerManageAddUri' => $schedulerManageAddUri,
            'schedulerCliCommandsJson' => json_encode($commands, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '[]',
            'schedulerCliTasksJson' => json_encode($tasks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);

        return $view->renderResponse('Module/SchedulerCli');
    }

    public function schedulerCliRunAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $payload = $this->normalizeRunPayload(is_array($body) ? $body : []);
        $result = $this->schedulerCliTaskService->runCommand($payload['command'], $payload['params']);
        $this->activityLogService->logSchedulerCommandRun(
            (string) $payload['command'],
            ($result['ok'] ?? 0) === 1,
        );

        return new RedirectResponse((string) $this->uriBuilder->buildUriFromRoute(
            't3af_dashboard.scheduler_cli',
            [
                'mode' => 'library',
                'flash' => $result['ok'] === 1 ? 'scheduler-run-ok' : 'scheduler-run-fail',
                'runOutput' => $result['ok'] === 1 ? (string) $result['output'] : (string) $result['error'],
            ],
        ));
    }

    public function buyCreditsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            return $this->redirectCreditsUnavailable($request);
        }

        $returnUrl = $this->creditsReturnUrlBuilder->fromRoute('t3af_dashboard.buy_credits');
        $creditsDashboard = $this->creditsDashboardService->buildForProvidersPage($returnUrl);

        $view = $this->createModuleView($request, 'buyCredits');
        $view->assignMultiple([
            'creditsDashboard' => $creditsDashboard,
            'products' => $creditsDashboard['products'] ?? [],
            'providersReturnUrl' => $returnUrl,
            'creditsModeEnabled' => self::fluidFlag($this->creditModeResolver->isEnabled()),
            'creditsModeActive' => self::fluidFlag($this->creditModeResolver->isActive()),
            'creditsFeatureAvailable' => self::fluidFlag($this->creditModeResolver->isPubliclyAvailable()),
        ]);

        return $view->renderResponse('Module/BuyCredits');
    }

    public function creditsPricingAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            return $this->redirectCreditsUnavailable($request);
        }

        $returnUrl = $this->creditsReturnUrlBuilder->fromRoute('t3af_dashboard.credits_pricing');
        $creditsDashboard = $this->creditsDashboardService->buildForProvidersPage($returnUrl);

        $view = $this->createModuleView($request, 'creditsPricing');
        $view->assignMultiple([
            'creditsDashboard' => $creditsDashboard,
            'features' => $creditsDashboard['features'] ?? [],
            'pricing' => $creditsDashboard['pricing'] ?? [],
            'creditsModeEnabled' => self::fluidFlag($this->creditModeResolver->isEnabled()),
            'creditsModeActive' => self::fluidFlag($this->creditModeResolver->isActive()),
            'creditsFeatureAvailable' => self::fluidFlag($this->creditModeResolver->isPubliclyAvailable()),
        ]);

        return $view->renderResponse('Module/CreditsPricing');
    }

    public function creditsCheckoutAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->creditModeResolver->isPubliclyAvailable()) {
            return $this->redirectCreditsUnavailable($request);
        }

        $checkoutUrl = trim((string) ($request->getQueryParams()['url'] ?? ''));
        if (!$this->checkoutUrlValidator->isAllowed($checkoutUrl)) {
            return new HtmlResponse(
                '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Checkout</title></head>'
                . '<body><p>Invalid or missing checkout URL.</p></body></html>',
                400,
            );
        }

        $html = $this->renderCreditsCheckoutFrame($request, $checkoutUrl);

        return new HtmlResponse($html);
    }

    private function renderCreditsCheckoutFrame(ServerRequestInterface $request, string $checkoutUrl): string
    {
        // ViewFactoryInterface is TYPO3 13+; StandaloneView covers TYPO3 12.
        if (class_exists(\TYPO3\CMS\Core\View\ViewFactoryInterface::class)
            && class_exists(\TYPO3\CMS\Core\View\ViewFactoryData::class)
        ) {
            return $this->renderCreditsCheckoutFrameWithCoreViewFactory($request, $checkoutUrl);
        }

        return $this->renderCreditsCheckoutFrameWithStandaloneView($request, $checkoutUrl);
    }

    private function renderCreditsCheckoutFrameWithCoreViewFactory(ServerRequestInterface $request, string $checkoutUrl): string
    {
        /** @var \TYPO3\CMS\Core\View\ViewFactoryInterface $viewFactory */
        $viewFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\View\ViewFactoryInterface::class);
        $viewFactoryDataClass = \TYPO3\CMS\Core\View\ViewFactoryData::class;
        /** @var \TYPO3\CMS\Core\View\ViewFactoryData $viewFactoryData */
        $viewFactoryData = new $viewFactoryDataClass(
            templateRootPaths: ['EXT:ns_t3af/Resources/Private/Templates/'],
            request: $request,
        );

        return $viewFactory
            ->create($viewFactoryData)
            ->assign('checkoutUrl', $checkoutUrl)
            ->render('Module/CreditsCheckoutFrame');
    }

    private function renderCreditsCheckoutFrameWithStandaloneView(ServerRequestInterface $request, string $checkoutUrl): string
    {
        if (!class_exists(StandaloneView::class)) {
            throw new \RuntimeException('No compatible Fluid view renderer available for credits checkout frame.');
        }

        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setRequest($request);
        $view->setTemplateRootPaths(['EXT:ns_t3af/Resources/Private/Templates/']);
        $view->setLayoutRootPaths(['EXT:ns_t3af/Resources/Private/Layouts/']);
        $view->setPartialRootPaths(['EXT:ns_t3af/Resources/Private/Partials/']);
        $view->setTemplate('Module/CreditsCheckoutFrame');
        $view->assign('checkoutUrl', $checkoutUrl);

        return $view->render();
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param array<string, scalar|null> $routeParams
     * @return list<array<string, mixed>>
     */
    private function enrichDashboardProviderCards(
        array $cards,
        array $routeParams,
        ?BackendUserAuthentication $backendUser,
    ): array {
        $canEdit = !$this->creditModeResolver->isActive()
            && $this->recordAccessEnforcer->canModifyTable($backendUser, 'tx_nst3af_provider');

        foreach ($cards as &$card) {
            $card['editUri'] = '';
            if (!$canEdit) {
                continue;
            }

            $provider = $card['provider'] ?? null;
            if (!$provider instanceof Provider) {
                continue;
            }

            $card['editUri'] = (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.providers.edit',
                array_merge($routeParams, ['uid' => $provider->uid]),
            );
        }
        unset($card);

        return $cards;
    }

    /**
     * @param array{preset:string,days:int,fromTimestamp:int,toTimestamp:int,labelKey:string} $period
     * @return list<array{id:string,label:string,href:string,active:int}>
     */
    private function buildDashboardPeriodPresets(array $period): array
    {
        return $this->buildPeriodPresets('t3af_dashboard.overview', $period);
    }

    /**
     * @param array{preset:string,days:int,fromTimestamp:int,toTimestamp:int,labelKey:string} $period
     * @param array<string, scalar|null> $extraParams
     *
     * @return list<array{id:string,label:string,href:string,active:int}>
     */
    private function buildPeriodPresets(string $route, array $period, array $extraParams = []): array
    {
        $activePreset = (string) $period['preset'];
        $presets = [
            DashboardPeriodResolver::PRESET_TODAY,
            DashboardPeriodResolver::PRESET_YESTERDAY,
            DashboardPeriodResolver::PRESET_7D,
            DashboardPeriodResolver::PRESET_14D,
            DashboardPeriodResolver::PRESET_30D,
        ];
        $rows = [];
        foreach ($presets as $preset) {
            $rows[] = [
                'id' => $preset,
                'label' => $this->translateModule('module.dashboard.period.' . $preset),
                'href' => (string) $this->uriBuilder->buildUriFromRoute(
                    $route,
                    array_merge($extraParams, ['period' => $preset]),
                ),
                'active' => self::fluidFlag($preset === $activePreset),
            ];
        }

        return $rows;
    }

    /**
     * @param array{preset:string,days:int,fromTimestamp:int,toTimestamp:int,labelKey:string} $period
     */
    private function formatPeriodLabel(array $period): string
    {
        return $period['preset'] === DashboardPeriodResolver::PRESET_CUSTOM
            ? $this->formatPeriodRangeLabel($period)
            : $this->translateModule($period['labelKey']);
    }

    /**
     * @param array<string, mixed> $query
     * @param array{
     *   search:string,
     *   engine:string,
     *   model:string,
     *   module:string,
     *   scope:string,
     *   reqType:string,
     *   status:string,
     *   user:string,
     *   max:int,
     *   currentPage:int
     * } $filters
     *
     * @return array<string, string>
     */
    private function buildAiUsageRouteContext(array $query, string $mode, array $filters): array
    {
        return [
            'mode' => $mode,
            'search' => $filters['search'],
            'engine' => $filters['engine'],
            'model' => $filters['model'],
            'module' => $filters['module'],
            'scope' => $filters['scope'],
            'reqType' => $filters['reqType'],
            'status' => $filters['status'],
            'user' => $filters['user'],
            'max' => (string) $filters['max'],
            'currentPage' => (string) ($filters['currentPage'] ?? 1),
        ];
    }

    /**
     * @param array{
     *   key:string,
     *   fromTimestamp:int,
     *   toTimestamp:int,
     *   fromDate?:string,
     *   toDate?:string
     * } $period
     *
     * @return array<string, string>
     */
    private function buildAiUsagePeriodRouteParams(array $period): array
    {
        if (($period['key'] ?? '') === DashboardPeriodResolver::PRESET_CUSTOM) {
            return [
                'period' => DashboardPeriodResolver::PRESET_CUSTOM,
                'from' => $period['fromDate'] ?? date('Y-m-d', (int) $period['fromTimestamp']),
                'to' => $period['toDate'] ?? date('Y-m-d', (int) $period['toTimestamp']),
            ];
        }

        return ['period' => (string) ($period['key'] ?? DashboardPeriodResolver::PRESET_7D)];
    }

    /**
     * @param array{preset:string,days:int,fromTimestamp:int,toTimestamp:int,labelKey:string} $period
     */
    private function formatPeriodRangeLabel(array $period): string
    {
        return date('M j, Y', (int) $period['fromTimestamp'])
            . ' – '
            . date('M j, Y', (int) $period['toTimestamp']);
    }

    /**
     * @param array{preset:string,days:int,fromTimestamp:int,toTimestamp:int,labelKey:string} $period
     */
    private function buildAiUsageUriFromDashboardPeriod(array $period): string
    {
        $params = ['period' => (string) ($period['preset'] ?? DashboardPeriodResolver::PRESET_7D)];
        if ($params['period'] === DashboardPeriodResolver::PRESET_CUSTOM) {
            $params['from'] = date('Y-m-d', (int) $period['fromTimestamp']);
            $params['to'] = date('Y-m-d', (int) $period['toTimestamp']);
        }

        return (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_usage', $params);
    }

    /**
     * @param array<string, mixed> $analyticsCredits
     * @param array<string, mixed> $analyticsOwnKeys
     * @return array<string, string>
     */
    private function buildDashboardChartAssignments(array $analyticsCredits, array $analyticsOwnKeys): array
    {
        $successLabel = $this->translateModule('dashboard.chart.success');
        $failedLabel = $this->translateModule('dashboard.chart.failed');

        return [
            'chartCreditsStackedBurn' => $this->dashboardChartConfigurator->creditsStackedBurnChart(
                $analyticsCredits['creditsByDayAndExtension'] ?? [],
                $this->translateModule('dashboard.chart.creditsBurn'),
            ),
            'chartCreditsBurn' => $this->dashboardChartConfigurator->creditsBurnChart(
                $analyticsCredits['creditsOverTime'] ?? [],
                $this->translateModule('dashboard.chart.creditsBurn'),
            ),
            'chartExtensionSpend' => $this->dashboardChartConfigurator->extensionDonutChart(
                $analyticsCredits['extensionUsage'] ?? [],
                $this->translateModule('dashboard.chart.extensionSpend'),
            ),
            'chartTopExtensionsCredits' => $this->dashboardChartConfigurator->horizontalBarChart(
                $analyticsCredits['extensionSpendRows'] ?? [],
                $this->translateModule('dashboard.chart.topExtensionsCredits'),
            ),
            'chartRequestsSuccessFail' => $this->dashboardChartConfigurator->requestsSuccessFailChart(
                $analyticsCredits['requestsSuccessFailOverTime'] ?? [],
                $successLabel,
                $failedLabel,
            ),
            'chartSuccessRateCredits' => $this->dashboardChartConfigurator->successRateDonutChart(
                $analyticsCredits['successFail'] ?? [],
                $successLabel,
                $failedLabel,
            ),
            'chartExtensionUsageCredits' => $this->dashboardChartConfigurator->extensionDonutChart(
                $analyticsCredits['extensionUsage'] ?? [],
                $this->translateModule('dashboard.chart.extensionUsage'),
            ),
            'chartRequests' => $this->dashboardChartConfigurator->requestsSuccessFailChart(
                $analyticsOwnKeys['requestsSuccessFailOverTime'] ?? [],
                $successLabel,
                $failedLabel,
            ),
            'chartSuccessRateOwnKeys' => $this->dashboardChartConfigurator->successRateDonutChart(
                $analyticsOwnKeys['successFail'] ?? [],
                $successLabel,
                $failedLabel,
            ),
            'chartExtensionUsageOwnKeys' => $this->dashboardChartConfigurator->extensionDonutChart(
                $analyticsOwnKeys['extensionUsage'] ?? [],
                $this->translateModule('dashboard.chart.extensionUsage'),
            ),
            'chartTopModels' => $this->dashboardChartConfigurator->horizontalBarChart(
                array_values(array_map(
                    static fn(array $row): array => [
                        'extensionKey' => (string) ($row['model'] ?? ''),
                        'tokens' => (int) ($row['tokens'] ?? 0),
                    ],
                    $analyticsOwnKeys['topModels'] ?? [],
                )),
                $this->translateModule('dashboard.chart.topModels'),
                'tokens',
            ),
            'chartProviderDistribution' => $this->dashboardChartConfigurator->extensionDonutChart(
                $this->mapProviderDistributionForChart($analyticsOwnKeys['providerDistribution'] ?? []),
                $this->translateModule('dashboard.chart.providerDistribution'),
            ),
            'chartCostTrend' => $this->dashboardChartConfigurator->costTrendMultiLineChart(
                $analyticsOwnKeys['costByDayAndProvider'] ?? [],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $creditsDashboard
     * @return array{runOutDate:string,dailyAvg:float,dailyAvgFormatted:string}
     */
    private function buildCreditProjection(array $creditsDashboard): array
    {
        $stats = is_array($creditsDashboard['stats'] ?? null) ? $creditsDashboard['stats'] : [];
        $balance = is_array($creditsDashboard['balance'] ?? null) ? $creditsDashboard['balance'] : [];
        $dailyAvg = (float) ($stats['dailyAverage'] ?? 0.0);
        $remaining = (float) ($balance['remaining'] ?? 0.0);
        $runOutDays = $dailyAvg > 0.0 ? (int) ceil($remaining / $dailyAvg) : 0;
        $runOutDate = $runOutDays > 0
            ? date('M j, Y', (int) ($GLOBALS['EXEC_TIME'] ?? time()) + $runOutDays * 86400)
            : '';

        return [
            'runOutDate' => $runOutDate,
            'dailyAvg' => $dailyAvg,
            'dailyAvgFormatted' => (string) ($stats['dailyAverageFormatted'] ?? number_format($dailyAvg, 1)),
        ];
    }

    /**
     * @param list<array{cost:float}> $rows
     */
    private function sumProviderCosts(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) ($row['cost'] ?? 0.0);
        }

        return round($sum, 2);
    }

    /**
     * @param list<array{provider:string,requests:int,cost:float}> $rows
     * @return list<array{extensionKey:string,requests:int,cost:float}>
     */
    private function mapProviderDistributionForChart(array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'extensionKey' => (string) ($row['provider'] ?? ''),
                'requests' => (int) ($row['requests'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0.0),
            ],
            $rows,
        );
    }

    private function renderTabPlaceholder(ServerRequestInterface $request, string $active): ResponseInterface
    {
        $view = $this->createModuleView($request, $active);

        return $view->renderResponse('Module/Tab');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveAiUsageMode(array $query): string
    {
        $mode = (string) ($query['mode'] ?? 'log');

        return in_array($mode, ['log', 'summary'], true) ? $mode : 'log';
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveSchedulerCliMode(array $query): string
    {
        $mode = (string) ($query['mode'] ?? 'tasks');

        return in_array($mode, ['library', 'tasks'], true) ? $mode : 'tasks';
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveAiPromptsMode(array $query): string
    {
        $mode = trim((string) ($query['mode'] ?? 'overview'));

        return in_array($mode, ['overview', 'detail'], true) ? $mode : 'overview';
    }

    /**
     * @param array<string, mixed> $query
     * @return array{search:string,extension:string,title:string,text:string,promptType:string,scope:string}
     */
    private function normalizeAiPromptsFilters(array $query): array
    {
        return [
            'search' => trim((string) ($query['search'] ?? '')),
            'extension' => trim((string) ($query['extension'] ?? 'all')),
            'title' => trim((string) ($query['title'] ?? '')),
            'text' => trim((string) ($query['text'] ?? '')),
            'promptType' => trim((string) ($query['promptType'] ?? 'all')),
            'scope' => trim((string) ($query['scope'] ?? 'all')),
        ];
    }

    private function redirectCreditsUnavailable(ServerRequestInterface $request): RedirectResponse
    {
        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.overview',
                array_merge($this->routeParamsForPage($request), ['flash' => 'credits-coming-soon']),
            ),
        );
    }

    private function redirectToAiUsage(ServerRequestInterface $request, string $flash): RedirectResponse
    {
        $body = $request->getParsedBody();
        $source = array_merge(
            $request->getQueryParams(),
            is_array($body) ? $body : [],
        );
        unset($source['uid'], $source['uids']);

        $period = $this->aiUsageAnalyticsService->resolvePeriod($source);
        $filters = $this->aiUsageAnalyticsService->normalizeFilters($source);

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_usage', array_merge(
                $this->buildAiUsageRouteContext($source, $this->resolveAiUsageMode($source), $filters),
                $this->buildAiUsagePeriodRouteParams($period),
                ['flash' => $flash],
            )),
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array{preset:string,days:int,fromTimestamp:int,toTimestamp:int,labelKey:string} $periodResolved
     *
     * @return array{
     *   logChannel: string,
     *   extension: string,
     *   level: string,
     *   search: string,
     *   max: int,
     *   currentPage: int,
     *   fromTimestamp: int,
     *   toTimestamp: int,
     *   period: string,
     *   from: string,
     *   to: string
     * }
     */
    private function normalizeAiLogsFilters(array $query, array $periodResolved): array
    {
        $maxRaw = (string) ($query['max'] ?? '20');
        $perPage = ctype_digit($maxRaw) ? (int) $maxRaw : 20;
        if (!in_array($perPage, [20, 50, 100, 200, 500], true)) {
            $perPage = 20;
        }

        $extension = trim((string) ($query['extension'] ?? ''));
        if ($extension === '' && isset($query['channel'])) {
            $extension = trim((string) $query['channel']);
        }

        return [
            'logChannel' => $this->aiLogChannelCatalog->normalizeLogChannel((string) ($query['logChannel'] ?? 'all')),
            'extension' => $this->aiLogChannelCatalog->normalizeExtension($extension),
            'level' => trim((string) ($query['level'] ?? '')),
            'search' => trim((string) ($query['search'] ?? '')),
            'max' => $perPage,
            'currentPage' => max(1, (int) ($query['currentPage'] ?? 1)),
            'fromTimestamp' => (int) $periodResolved['fromTimestamp'],
            'toTimestamp' => (int) $periodResolved['toTimestamp'],
            'period' => (string) $periodResolved['preset'],
            'from' => date('Y-m-d', (int) $periodResolved['fromTimestamp']),
            'to' => date('Y-m-d', (int) $periodResolved['toTimestamp']),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, string>
     */
    private function buildAiLogsRouteContext(array $filters): array
    {
        $context = [
            'logChannel' => (string) ($filters['logChannel'] ?? 'all'),
            'extension' => (string) ($filters['extension'] ?? 'all'),
            'level' => (string) ($filters['level'] ?? ''),
            'search' => (string) ($filters['search'] ?? ''),
            'max' => (string) ($filters['max'] ?? '50'),
            'currentPage' => (string) ($filters['currentPage'] ?? '1'),
        ];

        if (($filters['period'] ?? '') === DashboardPeriodResolver::PRESET_CUSTOM) {
            $context['period'] = DashboardPeriodResolver::PRESET_CUSTOM;
            $context['from'] = (string) ($filters['from'] ?? '');
            $context['to'] = (string) ($filters['to'] ?? '');
        } else {
            $context['period'] = (string) ($filters['period'] ?? DashboardPeriodResolver::PRESET_7D);
        }

        return $context;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function buildAiLogsLogChannelOptions(): array
    {
        $options = [];
        foreach ($this->aiLogChannelCatalog->buildLogChannelOptions() as $option) {
            $label = $this->translateModule($option['labelKey']);
            if ($label === $option['labelKey']) {
                $label = $option['value'] === 'all' ? '[ALL]' : $option['value'];
            }
            $options[] = [
                'value' => $option['value'],
                'label' => $label,
            ];
        }

        return $options;
    }

    private function buildExtensionTitleForKey(string $extensionKey): string
    {
        $extensionKey = trim($extensionKey);
        if ($extensionKey === '') {
            return '';
        }
        $labelKey = $this->resolveExtensionFilterLabelKey($extensionKey);
        $title = $this->translateDashboard($labelKey);

        return $title === $labelKey ? $extensionKey : $title;
    }

    /**
     * @param array<string, mixed> $feature
     * @return array<string, mixed>
     */
    private function enrichAiFeaturesCardWithExtensionTitle(array $feature): array
    {
        $filterExtensionKey = trim((string) ($feature['displayExtKey'] ?? $feature['extKey'] ?? ''));
        if ($filterExtensionKey !== '') {
            $feature['extensionTitle'] = $this->buildExtensionTitleForKey($filterExtensionKey);
        }

        return $feature;
    }

    /**
     * @param array<string, mixed> $category
     * @return array<string, mixed>
     */
    private function enrichAiPromptsCategoryWithExtensionTitle(array $category): array
    {
        $providerExtension = trim((string) ($category['providerExtension'] ?? ''));
        if ($providerExtension !== '') {
            $category['providerExtensionTitle'] = $this->buildExtensionTitleForKey($providerExtension);
        }

        return $category;
    }

    /**
     * @param list<string> $extensionKeys
     * @return list<array{key:string,title:string}>
     */
    private function buildExtensionTitleOptions(array $extensionKeys): array
    {
        $options = [];
        foreach ($extensionKeys as $extensionKey) {
            $key = trim((string) $extensionKey);
            if ($key === '') {
                continue;
            }
            $options[] = [
                'key' => $key,
                'title' => $this->buildExtensionTitleForKey($key),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function buildAiLogsExtensionOptions(): array
    {
        $options = [];
        foreach ($this->aiLogChannelCatalog->buildExtensionOptions() as $option) {
            $label = $this->translateModule($option['labelKey']);
            if ($label === $option['labelKey']) {
                $label = $option['value'] === 'all'
                    ? $this->translateModule('module.aiLogs.extension.all')
                    : $option['value'];
            }
            $options[] = [
                'value' => $option['value'],
                'label' => $label,
            ];
        }

        return $options;
    }

    private function redirectToAiLogs(ServerRequestInterface $request, string $flash): ResponseInterface
    {
        $body = $request->getParsedBody();
        $source = array_merge(
            $request->getQueryParams(),
            is_array($body) ? $body : [],
        );
        unset($source['uid'], $source['uids'], $source['operation']);

        $periodResolved = $this->dashboardPeriodResolver->resolveFromQueryParams(
            $source,
            DashboardPeriodResolver::PRESET_7D,
        );
        $filters = $this->normalizeAiLogsFilters($source, $periodResolved);

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(
                't3af_dashboard.ai_logs',
                array_filter(
                    array_merge(
                        $this->buildAiLogsRouteContext($filters),
                        ['flash' => $flash],
                    ),
                    static fn($value, string $key): bool => $key === 'flash'
                        || ($value !== '' && $value !== null && $value !== 'all'),
                    ARRAY_FILTER_USE_BOTH,
                ),
            ),
        );
    }

    private function redirectToAiPrompts(
        ServerRequestInterface $request,
        string $flash,
        string $category = 'seo',
        string $mode = 'overview',
        string $validationError = '',
        string $validationPromptType = '',
    ): RedirectResponse {
        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $source = array_merge(
            $query,
            is_array($body) ? $body : [],
        );
        $routeParams = array_merge(
            $this->routeParamsForPage($request),
            [
                'flash' => $flash,
                'mode' => $mode,
                'category' => $category,
                'search' => (string) ($source['search'] ?? ''),
                'extension' => (string) ($source['extension'] ?? 'all'),
            ],
        );
        if ($validationError !== '') {
            $routeParams['validationError'] = $validationError;
        }
        if ($validationPromptType !== '') {
            $routeParams['validationPromptType'] = $validationPromptType;
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts', $routeParams),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{command:string,params:array<string, scalar>}
     */
    private function normalizeRunPayload(array $body): array
    {
        $params = [];
        if (is_array($body['params'] ?? null)) {
            foreach ($body['params'] as $name => $value) {
                $key = trim((string) $name);
                if ($key === '') {
                    continue;
                }
                if (is_scalar($value)) {
                    $params[$key] = $value;
                }
            }
        }

        return [
            'command' => trim((string) ($body['command'] ?? '')),
            'params' => $params,
        ];
    }

    private function resolveExtensionFilterLabelKey(string $extensionKey): string
    {
        return match ($extensionKey) {
            'ns_t3ac/ns_t3as' => 'module.aiPrompts.extension.ns_t3cs',
            default => 'module.aiPrompts.extension.' . $extensionKey,
        };
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return list<array<string, mixed>>
     */
    private function localizeAiFeaturesCatalogDescriptions(array $catalog): array
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService === null) {
            return $this->stripAiFeaturesCatalogDescriptionLll($catalog);
        }

        foreach ($catalog as &$feature) {
            $descriptionLll = (string) ($feature['descriptionLll'] ?? '');
            unset($feature['descriptionLll']);

            if ($descriptionLll === '') {
                continue;
            }

            $translated = trim((string) $languageService->sL($descriptionLll));
            if (
                $translated !== ''
                && $translated !== $descriptionLll
                && !str_starts_with($translated, 'LLL:')
            ) {
                $feature['description'] = $translated;
            }
        }
        unset($feature);

        return $catalog;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     * @return list<array<string, mixed>>
     */
    private function stripAiFeaturesCatalogDescriptionLll(array $catalog): array
    {
        foreach ($catalog as &$feature) {
            unset($feature['descriptionLll']);
        }
        unset($feature);

        return $catalog;
    }

    private function shouldShowFullDashboardOverview(?BackendUserAuthentication $user): bool
    {
        if ($user === null || $user->isAdmin()) {
            return true;
        }

        return $this->shouldShowDashboardAnalytics($user)
            || $this->shouldShowAiContextOverview($user)
            || $this->shouldShowMcpOverview($user);
    }

    private function shouldShowDashboardAnalytics(BackendUserAuthentication $user): bool
    {
        return $this->moduleTabUtility->isTabVisible('aiUsage', $user);
    }

    private function shouldShowAiContextOverview(BackendUserAuthentication $user): bool
    {
        return $this->moduleTabUtility->isTabVisible('aiContext', $user);
    }

    private function shouldShowMcpOverview(BackendUserAuthentication $user): bool
    {
        return $this->moduleTabUtility->isTabVisible('mcpServer', $user)
            || $this->moduleTabUtility->isTabVisible('mcpTools', $user);
    }

    private function resolveAccessibleModuleRoute(BackendUserAuthentication $user, string $preferredRoute): string
    {
        $preferredTabKey = $this->moduleTabUtility->tabKeyForRoute($preferredRoute);
        if ($preferredTabKey !== null && !$this->moduleTabUtility->isTabVisible($preferredTabKey, $user)) {
            return $this->moduleTabUtility->firstVisibleNonDashboardTabRoute($user) ?? $preferredRoute;
        }

        if (
            $preferredRoute === 't3af_dashboard.overview'
            && !$this->shouldShowFullDashboardOverview($user)
        ) {
            return $this->moduleTabUtility->firstVisibleNonDashboardTabRoute($user) ?? $preferredRoute;
        }

        return $preferredRoute;
    }

    private function denyUnlessCanModifyUsageLog(ServerRequestInterface $request): ?RedirectResponse
    {
        if ($this->recordAccessEnforcer->canModifyCatalogId($this->getBackendUser(), AiUniverseRecordMap::USAGE_REQUEST_LOG)) {
            return null;
        }

        return $this->redirectToAiUsage($request, 'access-denied');
    }

    private function denyUnlessCanModifyGlobalPrompts(ServerRequestInterface $request): ?RedirectResponse
    {
        $category = $this->resolveAiPromptsCategoryFromRequest($request);
        $catalogId = $this->resolveGlobalPromptsCatalogId($category);
        if ($this->recordAccessEnforcer->canModifyCatalogId($this->getBackendUser(), $catalogId)) {
            return null;
        }

        return $this->redirectToAiPrompts($request, 'prompt-access-denied');
    }

    private function resolveAiPromptsCategoryFromRequest(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        if (isset($query['category']) && is_string($query['category']) && trim($query['category']) !== '') {
            return trim($query['category']);
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body['category']) && is_string($body['category']) && trim($body['category']) !== '') {
            return trim($body['category']);
        }

        return '';
    }

    private function resolveGlobalPromptsCatalogId(string $category): string
    {
        return AiUniverseRecordMap::AI_PROMPT_STORAGE;
    }
}
