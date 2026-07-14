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

use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;
use NITSAN\NsT3AF\Credits\Service\CreditsReleaseGate;
use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Service\EnvironmentRequirementService;
use NITSAN\NsT3AF\Service\ModuleStateService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\SiteStorageResolution;
use NITSAN\NsT3AF\Service\WizardExtensionCatalogService;
use NITSAN\NsT3AF\Service\WizardProgressService;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use NITSAN\NsT3AF\Utility\LicenseUtility;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use NITSAN\NsT3AF\Utility\PagePathUtility;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared backend module shell: document title, tab navigation, and common assigns.
 *
 * JSON-only actions in concrete controllers should not call {@see createModuleView}.
 *
 * @internal
 */
abstract class AbstractAiUniverseModuleController
{
    private const LOCALLANG_JS = 'EXT:ns_t3af/Resources/Private/Language/locallang_js.xlf';

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly UriBuilder $uriBuilder,
        protected readonly ModuleTabUtility $moduleTabUtility,
        protected readonly ProviderRepositoryInterface $providerRepository,
        protected readonly PageRenderer $pageRenderer,
        protected readonly CreditOverviewLineService $creditOverviewLine,
        protected readonly ModuleStateService $moduleStateService,
        protected readonly WizardProviderCatalog $wizardProviderCatalog,
        protected readonly WizardExtensionCatalogService $wizardExtensionCatalog,
        protected readonly SiteStorageContext $siteStorageContext,
        protected readonly WizardProgressService $wizardProgress,
    ) {}

    protected function createModuleView(ServerRequestInterface $request, string $activeTabKey): ModuleTemplate
    {
        $this->pageRenderer->addInlineLanguageLabelFile(self::LOCALLANG_JS);
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/module-page-context.js');

        if ($this->moduleTabUtility->isPersistableTab($activeTabKey)) {
            $backendUser = $this->getBackendUser();
            if ($backendUser instanceof BackendUserAuthentication) {
                $this->moduleStateService->setLastTab($backendUser, $activeTabKey);
            }
        }

        $view = $this->moduleTemplateFactory->create($request);
        $this->configureDocHeader($view, $activeTabKey);
        $view->assignMultiple($this->buildModuleShellAssignments($activeTabKey, $request));

        return $view;
    }

    private function configureDocHeader(ModuleTemplate $view, string $activeTabKey): void
    {
        $docHeader = $view->getDocHeaderComponent();
        $docHeader->disable();
        $docHeader->setMetaInformation([]);

        if (method_exists($docHeader, 'setShortcutContext')) {
            $docHeader->setShortcutContext(
                't3af_dashboard',
                $this->translateModule('module.title'),
            );
        }

        $moduleTitle = $this->translateModule('module.title');
        $tabLabel = $this->moduleTabUtility->navigationLabelFor(
            $activeTabKey,
            fn(string $key): string => $this->translateModule($key),
        );
        $view->setTitle($moduleTitle, $tabLabel);
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    protected function translateModule(string $key): string
    {
        return (string) ($GLOBALS['LANG']?->sL(
            'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf:' . $key,
        ) ?? $key);
    }

    protected function translateDashboard(string $key): string
    {
        return (string) ($GLOBALS['LANG']?->sL(
            'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf:' . $key,
        ) ?? $key);
    }

    /**
     * Labels for {@see self::LOCALLANG_JS} (backend module JavaScript via TYPO3.lang).
     */
    protected function translateJs(string $key): string
    {
        return (string) ($GLOBALS['LANG']?->sL(
            'LLL:' . self::LOCALLANG_JS . ':' . $key,
        ) ?? $key);
    }

    /** @return 0|1 Fluid-safe boolean for {@see f:if} comparisons (`== 1`). */
    protected static function fluidFlag(bool $value): int
    {
        return $value ? 1 : 0;
    }

    protected function resolveCreditOverviewLine(): string
    {
        return $this->creditOverviewLine->resolve();
    }

    protected function flashFromQuery(ServerRequestInterface $request): ?string
    {
        $flash = $request->getQueryParams()['flash'] ?? null;

        return is_string($flash) && $flash !== '' ? $flash : null;
    }

    protected function resolveSiteStorage(ServerRequestInterface $request): SiteStorageResolution
    {
        return $this->siteStorageContext->resolveFromRequest($request);
    }

    /**
     * @return array<string, int|string>
     */
    protected function routeParamsForPage(ServerRequestInterface $request): array
    {
        $pageId = $this->siteStorageContext->resolvePageIdFromRequest($request);

        return $pageId > 0 ? ['id' => $pageId] : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildModuleShellAssignments(string $activeTabKey, ServerRequestInterface $request): array
    {
        $backendUser = $this->getBackendUser();
        $routeParams = $this->routeParamsForPage($request);
        $resolution = $this->resolveSiteStorage($request);
        $rootPageMeta = $this->resolveRootPageMeta($resolution->pageId);
        $tabs = $this->moduleTabUtility->buildVisibleTabs(
            $activeTabKey,
            fn(string $key): string => $this->translateModule($key),
            fn(string $route): string => (string) $this->uriBuilder->buildUriFromRoute($route, $routeParams),
            $backendUser,
        );
        $tabGroups = $this->moduleTabUtility->buildNavigationTabGroups(
            $activeTabKey,
            fn(string $key): string => $this->translateModule($key),
            fn(string $route): string => (string) $this->uriBuilder->buildUriFromRoute($route, $routeParams),
            $backendUser,
        );
        foreach ($tabs as $key => $tab) {
            $tabs[$key]['active'] = self::fluidFlag((bool) ($tab['active'] ?? false));
        }
        foreach (['primary', 'utility'] as $group) {
            foreach ($tabGroups[$group] as $key => $tab) {
                $tabGroups[$group][$key]['active'] = self::fluidFlag((bool) ($tab['active'] ?? false));
            }
        }

        $tabContent = $this->moduleTabUtility->buildTabContent(
            $activeTabKey,
            fn(string $key): string => $this->translateModule($key),
        );

        // AI Permissions handles authorization in its controller (accessDenied).
        // Do not hide the Fluid content section via tabAccessDenied for this tab.
        $tabAccessDenied = $activeTabKey === 'aiAccessRoles'
            ? 0
            : self::fluidFlag(!$this->moduleTabUtility->isTabVisible($activeTabKey, $backendUser));

        $wizardCompleted = $this->wizardProgress->isCompleted();
        $licenseStatus = LicenseUtility::getModuleLicenseStatus();
        $licenseValid = (bool) ($licenseStatus['valid'] ?? false);

        return array_merge(
            [
                'moduleTitle' => $this->translateModule('module.title'),
                'moduleLogoIdentifier' => 'ns-t3af-header-logo',
                'moduleIconIdentifier' => (new Typo3Version())->getMajorVersion() >= 14 ? 'ns-t3af-foundation-module' : 'ns-t3af-foundation-module13',
                'modulePath' => $this->moduleTabUtility->resolveActivePath($activeTabKey),
                'creditOverview' => $this->resolveCreditOverviewLine(),
                'creditsFeatureAvailable' => self::fluidFlag((new CreditsReleaseGate())->isPubliclyAvailable()),
                'licenseBannerVisible' => self::fluidFlag(!$licenseValid),
                'licenseBannerShowGetKeyLink' => self::fluidFlag(
                    ($licenseStatus['reason'] ?? '') !== LicenseUtility::REASON_NS_LICENSE_MISSING,
                ),
                'quickSetupLabel' => $this->translateModule('module.quickSetup'),
                'wizardCompleted' => self::fluidFlag($wizardCompleted),
                'wizardAutoOpen' => self::fluidFlag(!$wizardCompleted && $tabAccessDenied === 0),
                'wizardResumeStep' => $this->wizardProgress->getLastStep(),
                'wizardMaxStep' => $this->wizardProgress->getMaxStep(),
                'providersUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers', $routeParams),
                'providersNewUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.providers.new', $routeParams),
                'dashboardUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.overview', $routeParams),
                'aiPromptsUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_prompts', $routeParams),
                'aiContextUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context', $routeParams),
                'aiFeaturesUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_features', $routeParams),
                'mcpServerUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.mcp_server', $routeParams),
                'schedulerCliUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.scheduler_cli', $routeParams),
                'siteStorageResolved' => self::fluidFlag($resolution->isResolved()),
                'requiresPageSelection' => self::fluidFlag(
                    $this->moduleTabUtility->isSiteScopedTab($activeTabKey) && !$resolution->isResolved(),
                ),
                'siteStoragePid' => $resolution->storagePid,
                'currentPageId' => $resolution->pageId,
                'pagePathData' => PagePathUtility::getCurrentPagePathData($resolution->pageId),
                'siteTitle' => $resolution->siteTitle,
                'siteStorageReason' => $resolution->reason,
                'rootPageUid' => $rootPageMeta['uid'],
                'rootPageTitle' => $rootPageMeta['title'],
                'wizardProvidersJson' => $this->encodeWizardProvidersJson($resolution->storagePid),
                'wizardCatalogJson' => $this->encodeWizardCatalogJson(),
                'wizardExtensionsJson' => $this->encodeWizardExtensionsJson(),
                'wizardUiJson' => $this->encodeWizardUiJson(),
                'wizardExtensionsAvailable' => self::fluidFlag($this->wizardExtensionCatalog->hasEligibleExtensions()),
                'brandContextIndustries' => BrandContextProfile::INDUSTRIES,
                'brandContextToneTags' => BrandContextProfile::TONE_TAGS,
                'tabs' => $tabs,
                'primaryTabs' => $tabGroups['primary'],
                'utilityTabs' => $tabGroups['utility'],
                'tabAccessDenied' => $tabAccessDenied,
            ],
            $tabContent,
        );
    }

    /**
     * Credential-encryption host gaps for AI Providers / drawer only (not every module tab).
     *
     * @return list<array{code: string, title: string, description: string}>
     */
    protected function credentialEnvironmentAlerts(): array
    {
        /** @var EnvironmentRequirementService $requirements */
        $requirements = GeneralUtility::makeInstance(EnvironmentRequirementService::class);

        return $requirements->failingCipherAlerts(
            fn(string $key): string => $this->translateModule($key),
        );
    }

    /**
     * @return array{uid:int,title:string}
     */
    private function resolveRootPageMeta(int $pageId): array
    {
        if ($pageId <= 0) {
            return ['uid' => 0, 'title' => ''];
        }

        $rootLine = BackendUtility::BEgetRootLine($pageId);
        foreach ($rootLine as $record) {
            if ((int) ($record['pid'] ?? -1) !== 0 || (int) ($record['uid'] ?? 0) <= 0) {
                continue;
            }

            return [
                'uid' => (int) $record['uid'],
                'title' => (string) (BackendUtility::getRecordTitle('pages', $record) ?: ($record['title'] ?? '')),
            ];
        }

        return ['uid' => 0, 'title' => ''];
    }

    private function encodeWizardExtensionsJson(): string
    {
        return json_encode(
            $this->wizardExtensionCatalog->buildCatalog(),
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }

    private function encodeWizardCatalogJson(): string
    {
        $rows = $this->wizardProviderCatalog->listForWizard(
            fn(string $key): string => $this->translateModule($key),
        );

        return json_encode($rows, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function encodeWizardProvidersJson(int $storagePid = 0): string
    {
        $rows = [];
        $providers = $storagePid > 0
            ? $this->providerRepository->findAllByStoragePid($storagePid, includeHidden: false)
            : $this->providerRepository->findAll(includeHidden: false);
        foreach ($providers as $provider) {
            if (!$provider->isEnabled) {
                continue;
            }
            $rows[] = [
                'uid' => $provider->uid,
                'title' => $provider->title,
                'identifier' => $provider->identifier,
                'adapterType' => $provider->adapterType,
                'adapterLabel' => $this->wizardProviderCatalog->adapterDisplayLabel($provider->adapterType),
                'modelId' => $provider->modelId,
                'isDefault' => $provider->isDefault,
                'hasApiKey' => trim($provider->apiKeyCipher) !== '',
            ];
        }

        return json_encode($rows, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function encodeWizardUiJson(): string
    {
        $payload = [
            'testMissingProvider' => $this->translateJs('wizard.error.selectProvider'),
            'testMissingUid' => $this->translateJs('wizard.error.testMissingUid'),
            'notifyOk' => $this->translateJs('wizard.notify.testOk'),
            'notifyFail' => $this->translateJs('wizard.notify.testFail'),
            'summaryMode' => $this->translateJs('wizard.summary.labelMode'),
            'summaryApi' => $this->translateJs('wizard.summary.labelApi'),
            'summaryExtensions' => $this->translateJs('wizard.summary.labelExtensions'),
            'summaryMcp' => $this->translateJs('wizard.summary.labelMcp'),
            'summaryBrandContext' => $this->translateJs('wizard.summary.labelBrandContext'),
            'brandContextSkipped' => $this->translateJs('wizard.summary.brandContextSkipped'),
            'brandContextConfigured' => $this->translateJs('wizard.summary.brandContextConfigured'),
            'toneTagsInvalid' => $this->translateJs('wizard.error.toneTagsInvalid'),
            'brandNameRequired' => $this->translateJs('wizard.error.brandNameRequired'),
            'industryRequired' => $this->translateJs('wizard.error.industryRequired'),
            'modeCredits' => $this->translateJs('wizard.summary.modeCredits'),
            'modeOwn' => $this->translateJs('wizard.summary.modeOwn'),
            'apiManagedCredits' => $this->translateJs('wizard.summary.apiManagedCredits'),
            'apiPendingTest' => $this->translateJs('wizard.summary.apiPendingTest'),
            'apiVerified' => $this->translateJs('wizard.summary.apiVerified'),
            'apiStored' => $this->translateJs('wizard.summary.apiStored'),
            'extensionsNone' => $this->translateJs('wizard.summary.extensionsNone'),
            'mcpOff' => $this->translateJs('wizard.summary.mcpOff'),
            'mcpOn' => $this->translateJs('wizard.summary.mcpOn'),
            'providerPickHeading' => $this->translateModule('wizard.step3.pickHeading'),
            'providerCreditsLead' => $this->translateModule('wizard.step3.creditsLead'),
            'providerEmpty' => $this->translateJs('wizard.step3.empty'),
            'providerDefaultBadge' => $this->translateModule('wizard.step3.defaultBadge'),
            'step4Lead' => $this->translateModule('wizard.step4.lead'),
            'step4LeadNoKey' => $this->translateModule('wizard.step4.leadNoKey'),
            'step4TestOkInline' => $this->translateModule('wizard.step4.testOkInline'),
            'keyUrlPrefix' => $this->translateModule('wizard.step4.keyUrlPrefix'),
            'adapterUnavailable' => $this->translateJs('wizard.error.adapterUnavailable'),
            'step5SuiteNote' => $this->translateModule('wizard.step5.suiteNote'),
            'summaryLeadIntro' => $this->translateJs('wizard.step8.summaryLeadIntro'),
            'needApiTest' => $this->translateJs('wizard.error.needApiTest'),
            'apiStoredUntested' => $this->translateJs('wizard.summary.apiStoredUntested'),
            'extensionsUnavailable' => $this->translateJs('wizard.step5.unavailable'),
            'extSelectedCount' => $this->translateModule('wizard.step5.selectedCount'),
            'extensionsActivated' => $this->translateJs('wizard.summary.extensionsActivated'),
            'finalizeOk' => $this->translateJs('wizard.notify.finalizeOk'),
            'finalizeFail' => $this->translateJs('wizard.notify.finalizeFail'),
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
