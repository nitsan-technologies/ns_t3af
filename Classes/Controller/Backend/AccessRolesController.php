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

use NITSAN\NsT3AF\Access\FeaturePermissionCatalog;
use NITSAN\NsT3AF\Access\GroupPresetRegistry;
use NITSAN\NsT3AF\Access\ModuleAccessCatalog;
use NITSAN\NsT3AF\Access\RecordPermissionCatalog;
use NITSAN\NsT3AF\Access\WizardBootstrapFactory;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;
use NITSAN\NsT3AF\Service\BeGroupAccessService;
use NITSAN\NsT3AF\Service\ModuleStateService;
use NITSAN\NsT3AF\Service\PermissionMatrixService;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Service\WizardExtensionCatalogService;
use NITSAN\NsT3AF\Service\WizardProgressService;
use NITSAN\NsT3AF\Service\WizardProviderCatalog;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

final class AccessRolesController extends AbstractAiUniverseModuleController
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
        private readonly BeGroupAccessService $beGroupAccessService,
        private readonly PermissionMatrixService $permissionMatrixService,
        private readonly GroupPresetRegistry $presetRegistry,
        private readonly ModuleAccessCatalog $moduleCatalog,
        private readonly FeaturePermissionCatalog $featureCatalog,
        private readonly RecordPermissionCatalog $recordCatalog,
        private readonly WizardBootstrapFactory $wizardBootstrapFactory,
        private readonly FeatureAccessBindingRegistry $bindingRegistry,
        private readonly CreditModeResolver $creditModeResolver,
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
        if (!$this->getBackendUser()?->isAdmin()) {
            $view = $this->createModuleView($request, 'aiAccessRoles');
            $view->assign('accessDenied', true);
            return $view->renderResponse('AccessRoles/Index');
        }

        $this->pageRenderer->addCssFile('EXT:ns_t3af/Resources/Public/Css/module/access-roles.css');
        $this->pageRenderer->loadJavaScriptModule('@nitsan/nst3af/access-roles.js');

        $query = $request->getQueryParams();
        $selectedGroup = (int) ($query['group'] ?? 0);
        $initialStep = (string) ($query['step'] ?? '');

        $view = $this->createModuleView($request, 'aiAccessRoles');

        try {
            $bootstrap = [
                'creditsModeEnabled' => $this->creditModeResolver->isEnabled(),
                'groups' => $this->beGroupAccessService->listGroupsSummary(),
                'presets' => $this->presetRegistry->allForBootstrap(),
                'defaultConfig' => $this->wizardBootstrapFactory->createDefaultConfig()->toArray(),
                'modules' => $this->moduleCatalog->allForBootstrap(),
                'features' => $this->featureCatalog->all(),
                'records' => $this->recordCatalog->all(),
                'featureRecordDefaults' => $this->bindingRegistry->featureRecordDefaults(),
                'matrix' => $this->permissionMatrixService->buildMatrix(),
                'selectedGroupUid' => $selectedGroup,
                'initialStep' => $initialStep,
                'providers' => [
                    ['id' => 't3planet', 'label' => 'T3Planet Credits'],
                    ['id' => 'openai', 'label' => 'OpenAI'],
                    ['id' => 'anthropic', 'label' => 'Anthropic Claude'],
                    ['id' => 'gemini', 'label' => 'Google Gemini'],
                    ['id' => 'ollama', 'label' => 'Ollama (Local)'],
                ],
            ];
            $bootstrapJson = json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);
            $bootstrapError = '';
        } catch (\Throwable $exception) {
            $bootstrapJson = '{}';
            $bootstrapError = $exception->getMessage();
        }

        $view->assignMultiple([
            'accessDenied' => false,
            'bootstrapJson' => $bootstrapJson,
            'bootstrapError' => $bootstrapError,
            'beGroupsEditUrl' => (string) $this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['be_groups' => ['0' => 'new']],
                'returnUrl' => (string) $request->getUri(),
            ]),
        ]);

        return $view->renderResponse('AccessRoles/Index');
    }
}
