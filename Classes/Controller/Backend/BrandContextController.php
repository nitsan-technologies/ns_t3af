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
use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;
use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Service\BrandContextCompletenessCalculator;
use NITSAN\NsT3AF\Service\BrandContextService;
use NITSAN\NsT3AF\Service\ModuleStateService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\WizardExtensionCatalogService;
use NITSAN\NsT3AF\Service\WizardProgressService;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Brand Context profiles: list, create, update, delete, set default.
 *
 * @internal
 */
final class BrandContextController extends AbstractAiUniverseModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        ModuleTabUtility $moduleTabUtility,
        ProviderRepositoryInterface $providerRepository,
        PageRenderer $pageRenderer,
        CreditOverviewLineService $creditOverviewLine,
        ModuleStateService $moduleStateService,
        WizardProviderCatalog $wizardProviderCatalog,
        WizardExtensionCatalogService $wizardExtensionCatalog,
        SiteStorageContext $siteStorageContext,
        WizardProgressService $wizardProgress,
        private readonly BrandContextService $brandContextService,
        private readonly RecordAccessEnforcer $recordAccessEnforcer,
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

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->createModuleView($request, 'aiContext');
        $resolution = $this->resolveSiteStorage($request);
        $routeParams = $this->routeParamsForPage($request);

        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/ai-context.js');

        if (!$resolution->isResolved()) {
            return $view->renderResponse('Module/PageSelectionRequired');
        }

        $listData = $this->brandContextService->buildListViewData($resolution->storagePid);
        $sectionLabels = [];
        foreach (BrandContextCompletenessCalculator::SECTIONS as $section) {
            $sectionLabels[] = [
                'id' => $section['id'],
                'label' => $this->translateDashboard($section['labelKey']),
            ];
        }

        $view->assignMultiple([
            'flash' => $this->flashFromQuery($request) ?? '',
            'canModifyBrandProfiles' => self::fluidFlag(
                $this->recordAccessEnforcer->canModifyCatalogId(
                    $this->getBackendUser(),
                    AiUniverseRecordMap::BRAND_PROFILES,
                ),
            ),
            'currentPageId' => $resolution->pageId,
            'brandContextStoragePid' => $resolution->storagePid,
            'brandContextList' => $listData,
            'brandContextIndustries' => BrandContextProfile::INDUSTRIES,
            'brandContextToneTags' => BrandContextProfile::TONE_TAGS,
            'brandContextPersonaLevels' => BrandContextProfile::PERSONA_LEVELS,
            'brandContextLanguages' => BrandContextProfile::LANGUAGES,
            'brandContextFormConfigJson' => json_encode([
                'toneTags' => BrandContextProfile::TONE_TAGS,
                'personaLevels' => BrandContextProfile::PERSONA_LEVELS,
                'sections' => $sectionLabels,
                'placeholders' => BrandContextService::PLACEHOLDERS,
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
            'brandContextCreateUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context.create', $routeParams),
            'brandContextUpdateUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context.update', $routeParams),
            'brandContextDeleteUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context.delete', $routeParams),
            'brandContextSetDefaultUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context.set_default', $routeParams),
            'brandContextSetEnabledUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context.set_enabled', $routeParams),
            'brandContextListUri' => (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context', $routeParams),
        ]);

        return $view->renderResponse('Module/AiContext');
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyBrandProfiles($request)) {
            return $denied;
        }
        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return $this->redirectToList($request, 'profile-validation-fail');
        }

        $payload = $this->parsePayload($request);
        $brandName = trim((string) ($payload['brandName'] ?? $payload['brand_name'] ?? ''));
        if ($brandName === '') {
            return $this->redirectToList($request, 'profile-validation-fail');
        }

        $this->brandContextService->createProfile($resolution->storagePid, $payload);

        return $this->redirectToList($request, 'profile-created');
    }

    public function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyBrandProfiles($request)) {
            return $denied;
        }
        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return $this->redirectToList($request, 'profile-validation-fail');
        }

        $payload = $this->parsePayload($request);
        $uid = (int) ($payload['uid'] ?? 0);
        $brandName = trim((string) ($payload['brandName'] ?? $payload['brand_name'] ?? ''));
        if ($uid <= 0 || $brandName === '') {
            return $this->redirectToList($request, 'profile-validation-fail');
        }

        $ok = $this->brandContextService->updateProfile($uid, $resolution->storagePid, $payload);

        return $this->redirectToList($request, $ok ? 'profile-updated' : 'profile-update-fail');
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyBrandProfiles($request)) {
            return $denied;
        }
        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return $this->redirectToList($request, 'profile-delete-fail');
        }

        $payload = $this->parsePayload($request);
        $uid = (int) ($payload['uid'] ?? 0);
        $ok = $uid > 0 && $this->brandContextService->deleteProfile($uid, $resolution->storagePid);

        return $this->redirectToList($request, $ok ? 'profile-deleted' : 'profile-delete-fail');
    }

    public function setDefaultAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyBrandProfiles($request)) {
            return $denied;
        }
        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return $this->redirectToList($request, 'profile-default-fail');
        }

        $payload = $this->parsePayload($request);
        $uid = (int) ($payload['uid'] ?? 0);
        $ok = $uid > 0 && $this->brandContextService->setDefaultProfile($uid, $resolution->storagePid);

        return $this->redirectToList($request, $ok ? 'profile-default-set' : 'profile-default-fail');
    }

    public function setEnabledAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessCanModifyBrandProfiles($request)) {
            return $denied;
        }
        $resolution = $this->resolveSiteStorage($request);
        if (!$resolution->isResolved()) {
            return $this->redirectToList($request, 'profile-enabled-fail');
        }

        $payload = $this->parsePayload($request);
        $uid = (int) ($payload['uid'] ?? 0);
        $enabled = (string) ($payload['enabled'] ?? '1') !== '0';
        $ok = $uid > 0 && $this->brandContextService->setProfileEnabled($uid, $resolution->storagePid, $enabled);

        return $this->redirectToList($request, $ok ? ($enabled ? 'profile-enabled' : 'profile-disabled') : 'profile-enabled-fail');
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePayload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function redirectToList(ServerRequestInterface $request, string $flash): RedirectResponse
    {
        $routeParams = array_merge($this->routeParamsForPage($request), ['flash' => $flash]);
        $uri = (string) $this->uriBuilder->buildUriFromRoute('t3af_dashboard.ai_context', $routeParams);

        return new RedirectResponse($uri);
    }

    private function denyUnlessCanModifyBrandProfiles(ServerRequestInterface $request): ?RedirectResponse
    {
        if ($this->recordAccessEnforcer->canModifyCatalogId($this->getBackendUser(), AiUniverseRecordMap::BRAND_PROFILES)) {
            return null;
        }

        return $this->redirectToList($request, 'profile-access-denied');
    }
}
