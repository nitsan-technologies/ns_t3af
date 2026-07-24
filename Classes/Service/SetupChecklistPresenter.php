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

use NITSAN\NsT3AF\Access\ModuleTabAccessService;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsDashboardService;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Domain\RequestLog\RequestLogProviderScope;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Builds localized setup checklist assigns for AI Foundation and child module dashboards.
 */
final class SetupChecklistPresenter
{
    private const LOCALLANG_MOD = 'EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf';

    private const BASE_CSS = 'EXT:ns_t3af/Resources/Public/Css/module/base.css';

    private const CHECKLIST_CSS = 'EXT:ns_t3af/Resources/Public/Css/module/setup.css';

    private const PARTIAL_ROOT = 'EXT:ns_t3af/Resources/Private/Partials/';

    public function __construct(
        private readonly SetupChecklistService $setupChecklistService,
        private readonly DashboardAnalyticsService $dashboardAnalytics,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly CreditsDashboardService $creditsDashboardService,
        private readonly CreditsReturnUrlBuilder $creditsReturnUrlBuilder,
        private readonly UriBuilder $uriBuilder,
        private readonly ModuleTabAccessService $tabAccessService,
    ) {}

    /**
     * @param array<string, mixed>|null $analyticsCredits
     * @param array<string, mixed>|null $analyticsOwnKeys
     * @param array<string, mixed>|null $creditsDashboard
     *
     * @return array{
     *   creditsModeEnabled: int,
     *   setupChecklistCredits: array<string, mixed>,
     *   setupChecklistOwnKeys: array<string, mixed>,
     *   setupChecklist: array<string, mixed>
     * }
     */
    public function buildAssigns(
        ServerRequestInterface $request,
        ?array $analyticsCredits = null,
        ?array $analyticsOwnKeys = null,
        int $storagePid = 0,
        ?array $creditsDashboard = null,
    ): array {
        $analyticsCredits ??= $this->dashboardAnalytics->forLastDays(7, RequestLogProviderScope::Credits);
        $analyticsOwnKeys ??= $this->dashboardAnalytics->forLastDays(7, RequestLogProviderScope::OwnKeys, $storagePid);

        if ($creditsDashboard === null) {
            $dashboardUri = $this->creditsReturnUrlBuilder->fromRoute('t3af_dashboard.overview');
            $creditsDashboard = $this->creditsDashboardService->buildForProvidersPage($dashboardUri);
        }
        $creditsModeEnabled = $this->creditModeResolver->isEnabled();

        $checklistCreditsRaw = $this->setupChecklistService->build(
            $analyticsCredits,
            RequestLogProviderScope::Credits,
            true,
            $creditsDashboard,
            $request,
        );
        $checklistOwnKeysRaw = $this->setupChecklistService->build(
            $analyticsOwnKeys,
            RequestLogProviderScope::OwnKeys,
            false,
            $creditsDashboard,
            $request,
        );

        return [
            'creditsModeEnabled' => self::fluidFlag($creditsModeEnabled),
            'setupChecklistCredits' => $this->localize($checklistCreditsRaw, $request),
            'setupChecklistOwnKeys' => $this->localize($checklistOwnKeysRaw, $request),
            'setupChecklist' => $this->localize($creditsModeEnabled ? $checklistCreditsRaw : $checklistOwnKeysRaw, $request),
        ];
    }

    /**
     * Same as {@see buildAssigns()} but adds showSetupChecklist for child module dashboards.
     *
     * @param array<string, mixed>|null $analyticsCredits
     * @param array<string, mixed>|null $analyticsOwnKeys
     * @param array<string, mixed>|null $creditsDashboard
     * @return array{
     *   creditsModeEnabled: int,
     *   setupChecklistCredits: array<string, mixed>,
     *   setupChecklistOwnKeys: array<string, mixed>,
     *   setupChecklist: array<string, mixed>,
     *   showSetupChecklist: 0|1
     * }
     */
    public function buildChildAssigns(
        ServerRequestInterface $request,
        ?array $analyticsCredits = null,
        ?array $analyticsOwnKeys = null,
        int $storagePid = 0,
        ?array $creditsDashboard = null,
    ): array {
        $assigns = $this->buildAssigns($request, $analyticsCredits, $analyticsOwnKeys, $storagePid, $creditsDashboard);
        $assigns['showSetupChecklist'] = self::fluidFlag(
            !$this->isComplete($assigns['setupChecklist']),
        );

        return $assigns;
    }

    /**
     * @param array{
     *   percent?: int,
     *   done?: int,
     *   warnings?: int,
     *   incomplete?: int,
     *   items?: list<array<string, mixed>>
     * } $setupChecklist
     */
    public function isComplete(array $setupChecklist): bool
    {
        $items = $setupChecklist['items'] ?? [];
        if ($items === []) {
            return true;
        }

        $total = count($items);
        $done = (int) ($setupChecklist['done'] ?? 0);
        $incomplete = (int) ($setupChecklist['incomplete'] ?? 0);

        return $done >= $total && $incomplete === 0;
    }

    public function registerAssets(PageRenderer $pageRenderer): void
    {
        $pageRenderer->addCssFile(self::BASE_CSS);
        $pageRenderer->addCssFile(self::CHECKLIST_CSS);
        // Child module layouts (ns_t3aa, ns_t3cs, …) append theme CSS via <link> after PageRenderer output.
        // Re-load shared UI styles in the footer so TYPO3 core card/badge tokens win over legacy Bootstrap.
        $this->appendStylesheetInFooter($pageRenderer, self::BASE_CSS);
        $this->appendStylesheetInFooter($pageRenderer, self::CHECKLIST_CSS);
        $pageRenderer->addInlineLanguageLabelFile(self::LOCALLANG_MOD);
        $pageRenderer->loadJavaScriptModule('@nitsan/nst3af/setup-checklist.js');
    }

    private function appendStylesheetInFooter(PageRenderer $pageRenderer, string $cssFile): void
    {
        $href = PathUtility::getPublicResourceWebPath($cssFile);
        $pageRenderer->addFooterData(
            sprintf('<link rel="stylesheet" href="%s" data-aiu-shared-ui="1" />', htmlspecialchars($href)),
        );
    }

    public function configureViewPartials(ModuleTemplate $moduleTemplate): void
    {
        $view = $this->resolveModuleTemplateView($moduleTemplate);
        if ($view !== null) {
            $this->appendAiUniversePartialRootPath($view);
        }
    }

    /**
     * View assigns for child module docheader aside (utility dropdown + AI Foundation link).
     *
     * @return array{
     *   utilityTabs: list<array{title: string, href: string, active: int}>,
     *   aiFoundationUri: string,
     *   showAiFoundationLink: int,
     *   aiUniverseLogsUri: string
     * }
     */
    public function buildChildDocHeaderAsideAssigns(int $pageId = 0, string $extensionKey = ''): array
    {
        if (!ExtensionManagementUtility::isLoaded('ns_t3af')) {
            return self::emptyChildDocHeaderAsideAssigns();
        }

        $routeParams = $pageId > 0 ? ['id' => $pageId] : [];
        $backendUser = $this->resolveBackendUser();
        $utilityTabs = [];

        if ($this->tabAccessService->isTabVisible('aiUsage', $backendUser)) {
            $utilityTabs[] = [
                'title' => $this->translateModule('module.menu.aiUsage'),
                'href' => (string) $this->uriBuilder->buildUriFromRoute(
                    't3af_dashboard.ai_usage',
                    array_merge($routeParams, ['period' => DashboardPeriodResolver::PRESET_7D]),
                ),
                'active' => 0,
            ];
        }

        if ($this->tabAccessService->isTabVisible('aiLogs', $backendUser)) {
            $logParams = array_merge($routeParams, ['period' => DashboardPeriodResolver::PRESET_7D]);
            if ($extensionKey !== '') {
                $logParams['extension'] = $extensionKey;
            }
            $utilityTabs[] = [
                'title' => $this->translateModule('module.menu.aiLogs'),
                'href' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_logs', $logParams),
                'active' => 0,
            ];
        }

        $aiFoundationUri = $this->tabAccessService->isTabVisible('dashboard', $backendUser)
            ? (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.overview', $routeParams)
            : '';

        return [
            'utilityTabs' => $utilityTabs,
            'aiFoundationUri' => $aiFoundationUri,
            'showAiFoundationLink' => self::fluidFlag($aiFoundationUri !== ''),
            'aiUniverseLogsUri' => $aiFoundationUri,
        ];
    }

    /**
     * Assign utility dropdown + AI Foundation link for child module docheaders.
     */
    public function assignChildDocHeaderAside(ModuleTemplate $moduleTemplate, ServerRequestInterface $request, string $extensionKey = ''): void
    {
        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);
        $moduleTemplate->assignMultiple($this->buildChildDocHeaderAsideAssigns($pageId, $extensionKey));
    }

    /**
     * Register ns_t3af partial paths on Extbase Fluid views.
     */
    public function configureExtbaseViewPartials(object $view): void
    {
        $this->appendAiUniversePartialRootPath($view);
    }

    private function appendAiUniversePartialRootPath(object $view): void
    {
        $renderingContext = $this->resolveRenderingContext($view);
        if ($renderingContext === null) {
            return;
        }
        $paths = $renderingContext->getTemplatePaths();
        $partialRoot = GeneralUtility::getFileAbsFileName(self::PARTIAL_ROOT);
        if ($partialRoot === '') {
            return;
        }
        $current = $paths->getPartialRootPaths();
        if (!in_array($partialRoot, $current, true)) {
            $paths->setPartialRootPaths([...$current, $partialRoot]);
        }
    }

    private function resolveRenderingContext(object $view): ?RenderingContextInterface
    {
        // The actual Fluid view is wrapped in a view adapter that differs between TYPO3 versions:
        // TYPO3\CMS\Core\View\FluidViewAdapter (v12) vs. TYPO3\CMS\Fluid\View\FluidViewAdapter (v13+).
        // Both expose the wrapped view through a protected "view" property, so unwrap generically
        // until we reach a view that provides the rendering context.
        $guard = 0;
        while (!method_exists($view, 'getRenderingContext') && $guard < 5) {
            $inner = $this->unwrapView($view);
            if ($inner === null || $inner === $view) {
                return null;
            }
            $view = $inner;
            $guard++;
        }

        if (method_exists($view, 'getRenderingContext')) {
            /** @var RenderingContextInterface $renderingContext */
            $renderingContext = $view->getRenderingContext();
            return $renderingContext;
        }

        return null;
    }

    private function unwrapView(object $view): ?object
    {
        $reflection = new \ReflectionClass($view);
        if (!$reflection->hasProperty('view')) {
            return null;
        }
        $property = $reflection->getProperty('view');
        $inner = $property->getValue($view);

        return is_object($inner) ? $inner : null;
    }

    private function resolveModuleTemplateView(ModuleTemplate $moduleTemplate): ?object
    {
        $reflection = new \ReflectionClass($moduleTemplate);
        if (!$reflection->hasProperty('view')) {
            return null;
        }
        $property = $reflection->getProperty('view');
        $view = $property->getValue($moduleTemplate);

        return is_object($view) ? $view : null;
    }

    /**
     * @param array{
     *   percent:int,
     *   done:int,
     *   warnings:int,
     *   incomplete:int,
     *   items:list<array{status:string,titleKey:string,descKey:string,descArgs?:list<string>,actionRoute:string,actionTabKey?:string|null}>
     * } $raw
     *
     * @return array{
     *   percent:int,
     *   progressDash:string,
     *   done:int,
     *   warnings:int,
     *   incomplete:int,
     *   foundationUri:string,
     *   items:list<array{status:string,title:string,description:string,actionUri:string,actionLabel:string}>
     * }
     */
    private function localize(array $raw, ServerRequestInterface $request): array
    {
        $pageId = (int) ($request->getQueryParams()['id'] ?? 0);
        $routeParams = $pageId > 0 ? ['id' => $pageId] : [];
        $user = $GLOBALS['BE_USER'] ?? null;
        $backendUser = $user instanceof BackendUserAuthentication ? $user : null;

        $foundationUri = '';
        if ($this->tabAccessService->isTabVisible('dashboard', $backendUser)) {
            $foundationUri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.overview', $routeParams);
        }

        $items = [];
        foreach ($raw['items'] as $item) {
            $pattern = $this->translateModule($item['descKey']);
            $description = isset($item['descArgs']) ? sprintf($pattern, ...$item['descArgs']) : $pattern;

            $actionUri = '';
            $actionLabel = '';
            $actionRoute = (string) ($item['actionRoute'] ?? '');
            if ($actionRoute !== '') {
                $tabKey = $item['actionTabKey'] ?? null;
                if ($tabKey === null || $this->tabAccessService->isTabVisible((string) $tabKey, $backendUser)) {
                    $actionUri = (string) $this->uriBuilder->buildUriFromRoute($actionRoute, $routeParams);
                    $actionLabel = $this->resolveActionLabel((string) $item['status']);
                }
            }

            $items[] = [
                'status' => $item['status'],
                'title' => $this->translateModule($item['titleKey']),
                'description' => $description,
                'actionUri' => $actionUri,
                'actionLabel' => $actionLabel,
            ];
        }

        return [
            'percent' => $raw['percent'],
            'progressDash' => (string) (int) max(0, min(97, round($raw['percent'] * 97 / 100))),
            'done' => $raw['done'],
            'warnings' => $raw['warnings'],
            'incomplete' => $raw['incomplete'],
            'foundationUri' => $foundationUri,
            'items' => $items,
        ];
    }

    private function resolveActionLabel(string $status): string
    {
        return match ($status) {
            'ok' => $this->translateModule('checklist.action.open'),
            'warn' => $this->translateModule('checklist.action.configure'),
            default => $this->translateModule('checklist.action.fix'),
        };
    }

    private function translateModule(string $key): string
    {
        return (string) ($GLOBALS['LANG']?->sL(
            'LLL:' . self::LOCALLANG_MOD . ':' . $key,
        ) ?? $key);
    }

    private function resolveBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    /**
     * @return array{
     *   utilityTabs: list<array{title: string, href: string, active: int}>,
     *   aiFoundationUri: string,
     *   showAiFoundationLink: int,
     *   aiUniverseLogsUri: string
     * }
     */
    private static function emptyChildDocHeaderAsideAssigns(): array
    {
        return [
            'utilityTabs' => [],
            'aiFoundationUri' => '',
            'showAiFoundationLink' => 0,
            'aiUniverseLogsUri' => '',
        ];
    }

    /** @return 0|1 */
    private static function fluidFlag(bool $value): int
    {
        return $value ? 1 : 0;
    }
}
