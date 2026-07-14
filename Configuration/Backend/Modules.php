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

use NITSAN\NsT3AF\Controller\Backend\AccessRolesController;
use NITSAN\NsT3AF\Controller\Backend\BrandContextController;
use NITSAN\NsT3AF\Controller\Backend\ModuleController;
use NITSAN\NsT3AF\Controller\Backend\ProviderController;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Information\Typo3Version;

$pageTreeNavigationComponent = AiUniverseUtilityHelper::getPageTreeNavigationComponent();

$isV14OrHigher = (new Typo3Version())->getMajorVersion() >= 14;

$t3afModule = [
    'labels' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf',
    'position' => ['after' => 'web'],
    'access' => 'user',
    'workspaces' => '*',
];
if ($isV14OrHigher) {
    // TYPO3 v14 registers a dedicated module icon (module-t3af) from the SVG path.
    $t3afModule['icon'] = 'EXT:ns_t3af/Resources/Public/Icons/ModuleV14.svg';
} else {
    $t3afModule['iconIdentifier'] = 'ns-t3af-module13';
}

$t3afDashboardModule = [
    'parent' => 't3af',
    'access' => 'user',
    'path' => '/module/t3af/dashboard',
];
if ($isV14OrHigher) {
    $t3afDashboardModule['icon'] = 'EXT:ns_t3af/Resources/Public/Icons/FoundationModuleV14.svg';
} else {
    $t3afDashboardModule['iconIdentifier'] = 'ns-t3af-foundation-module13';
}

return [
    't3af' => $t3afModule,
    't3af_dashboard' => $t3afDashboardModule + [
        'labels' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_mod_dashboard.xlf',
        'extensionName' => 'NsT3AF',
        'navigationComponent' => $pageTreeNavigationComponent,
        'routes' => [
            '_default' => [
                'target' => ModuleController::class . '::indexAction',
            ],
            'overview' => [
                'target' => ModuleController::class . '::overviewAction',
            ],
            'providers' => [
                'target' => ProviderController::class . '::indexAction',
            ],
            'providers.new' => [
                'target' => ProviderController::class . '::newAction',
            ],
            'providers.edit' => [
                'target' => ProviderController::class . '::editAction',
            ],
            'providers.save' => [
                'target' => ProviderController::class . '::saveAction',
                'methods' => ['POST'],
            ],
            'providers.delete' => [
                'target' => ProviderController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
            'ai_context' => [
                'target' => BrandContextController::class . '::indexAction',
            ],
            'ai_context.create' => [
                'target' => BrandContextController::class . '::createAction',
                'methods' => ['POST'],
            ],
            'ai_context.update' => [
                'target' => BrandContextController::class . '::updateAction',
                'methods' => ['POST'],
            ],
            'ai_context.delete' => [
                'target' => BrandContextController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
            'ai_context.set_default' => [
                'target' => BrandContextController::class . '::setDefaultAction',
                'methods' => ['POST'],
            ],
            'ai_context.set_enabled' => [
                'target' => BrandContextController::class . '::setEnabledAction',
                'methods' => ['POST'],
            ],
            'ai_access_roles' => [
                'target' => AccessRolesController::class . '::indexAction',
            ],
            'for_developers' => [
                'target' => ModuleController::class . '::forDevelopersAction',
            ],
            'mcp_server' => [
                'target' => ModuleController::class . '::mcpServerAction',
            ],
            'mcp_tools' => [
                'target' => ModuleController::class . '::mcpToolsAction',
            ],
            'mcp_connectors' => [
                'target' => ModuleController::class . '::mcpConnectorsAction',
            ],
            'ai_features' => [
                'target' => ModuleController::class . '::aiFeaturesAction',
            ],
            'ai_usage' => [
                'target' => ModuleController::class . '::aiUsageAction',
            ],
            'ai_usage.delete' => [
                'target' => ModuleController::class . '::aiUsageDeleteAction',
                'methods' => ['POST'],
            ],
            'ai_usage.bulk_delete' => [
                'target' => ModuleController::class . '::aiUsageBulkDeleteAction',
                'methods' => ['POST'],
            ],
            'ai_usage.export' => [
                'target' => ModuleController::class . '::aiUsageExportAction',
            ],
            'ai_logs' => [
                'target' => ModuleController::class . '::aiLogsAction',
            ],
            'ai_logs.delete' => [
                'target' => ModuleController::class . '::aiLogsDeleteAction',
                'methods' => ['POST'],
            ],
            'ai_logs.export' => [
                'target' => ModuleController::class . '::aiLogsExportAction',
            ],
            'ai_prompts' => [
                'target' => ModuleController::class . '::aiPromptsAction',
            ],
            'ai_prompts.sync' => [
                'target' => ModuleController::class . '::aiPromptsSyncAction',
                'methods' => ['POST'],
            ],
            'ai_prompts.create' => [
                'target' => ModuleController::class . '::aiPromptsCreateAction',
                'methods' => ['POST'],
            ],
            'ai_prompts.update' => [
                'target' => ModuleController::class . '::aiPromptsUpdateAction',
                'methods' => ['POST'],
            ],
            'ai_prompts.delete' => [
                'target' => ModuleController::class . '::aiPromptsDeleteAction',
                'methods' => ['POST'],
            ],
            'scheduler_cli' => [
                'target' => ModuleController::class . '::schedulerCliAction',
            ],
            'scheduler_cli.run' => [
                'target' => ModuleController::class . '::schedulerCliRunAction',
                'methods' => ['POST'],
            ],
            'buy_credits' => [
                'target' => ModuleController::class . '::buyCreditsAction',
            ],
            'credits_pricing' => [
                'target' => ModuleController::class . '::creditsPricingAction',
            ],
            'credits_checkout' => [
                'target' => ModuleController::class . '::creditsCheckoutAction',
            ],
        ],
    ],
];
