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

use NITSAN\NsT3AF\Controller\Backend\AccessRolesAjaxController;
use NITSAN\NsT3AF\Controller\Backend\BrandContextAjaxController;
use NITSAN\NsT3AF\Controller\Backend\CreditModeController;
use NITSAN\NsT3AF\Controller\Backend\FeatureSettingsController;
use NITSAN\NsT3AF\Controller\Backend\ProviderController;
use NITSAN\NsT3AF\Controller\Backend\WizardController;
use NITSAN\NsT3AF\Mcp\Controller\Backend\McpServerController;
use NITSAN\NsT3AF\Mcp\Controller\Backend\McpToolsController;

/**
 * Backend AJAX routes for the provider list view.
 *
 * Each entry registers a JSON endpoint reachable via TYPO3's standard backend
 * AJAX dispatcher (`@typo3/core/ajax/ajax-request`). Session + CSRF protection
 * come from the dispatcher; the controller methods only deal with the payload.
 */
return [
    'nst3af_provider_test' => [
        'path' => '/nst3af/provider/test',
        'target' => ProviderController::class . '::testAction',
    ],
    'nst3af_provider_set_default' => [
        'path' => '/nst3af/provider/set-default',
        'target' => ProviderController::class . '::setDefaultAction',
    ],
    'nst3af_provider_search' => [
        'path' => '/nst3af/provider/search',
        'target' => ProviderController::class . '::searchAction',
    ],
    'nst3af_provider_models' => [
        'path' => '/nst3af/provider/models',
        'target' => ProviderController::class . '::modelsAction',
    ],
    'nst3af_provider_import_sources' => [
        'path' => '/nst3af/provider/import-sources',
        'target' => ProviderController::class . '::importSourcesAction',
    ],
    'nst3af_provider_import_execute' => [
        'path' => '/nst3af/provider/import-execute',
        'target' => ProviderController::class . '::importExecuteAction',
        'methods' => ['POST'],
    ],
    'nst3af_wizard_finalize' => [
        'path' => '/nst3af/wizard/finalize',
        'target' => WizardController::class . '::finalizeAction',
        'methods' => ['POST'],
    ],
    'nst3af_wizard_ensure_provider' => [
        'path' => '/nst3af/wizard/ensure-provider',
        'target' => WizardController::class . '::ensureProviderAction',
        'methods' => ['POST'],
    ],
    'nst3af_wizard_progress' => [
        'path' => '/nst3af/wizard/progress',
        'target' => WizardController::class . '::progressAction',
        'methods' => ['POST'],
    ],
    'nst3af_credits_status' => [
        'path' => '/nst3af/credits/status',
        'target' => CreditModeController::class . '::statusAction',
    ],
    'nst3af_credits_toggle' => [
        'path' => '/nst3af/credits/toggle',
        'target' => CreditModeController::class . '::toggleAction',
        'methods' => ['POST'],
    ],
    'nst3af_credits_save_license' => [
        'path' => '/nst3af/credits/save-license',
        'target' => CreditModeController::class . '::saveLicenseAction',
        'methods' => ['POST'],
    ],
    'nst3af_credits_activate' => [
        'path' => '/nst3af/credits/activate',
        'target' => CreditModeController::class . '::activateAction',
        'methods' => ['POST'],
    ],
    'nst3af_credits_dashboard' => [
        'path' => '/nst3af/credits/dashboard',
        'target' => CreditModeController::class . '::dashboardAction',
    ],
    'nst3af_credits_balance' => [
        'path' => '/nst3af/credits/balance',
        'target' => CreditModeController::class . '::balanceAction',
    ],
    'nst3af_credits_current_plan' => [
        'path' => '/nst3af/credits/current-plan',
        'target' => CreditModeController::class . '::currentPlanAction',
    ],
    'nst3af_credits_features' => [
        'path' => '/nst3af/credits/features',
        'target' => CreditModeController::class . '::featuresAction',
    ],
    'nst3af_credits_products' => [
        'path' => '/nst3af/credits/products',
        'target' => CreditModeController::class . '::productsAction',
    ],
    'nst3af_credits_estimate' => [
        'path' => '/nst3af/credits/estimate',
        'target' => CreditModeController::class . '::estimateAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_status' => [
        'path' => '/nst3af/mcp/status',
        'target' => McpServerController::class . '::statusAction',
    ],
    'nst3af_mcp_connections' => [
        'path' => '/nst3af/mcp/connections',
        'target' => McpServerController::class . '::connectionsAction',
    ],
    'nst3af_mcp_token_revoke' => [
        'path' => '/nst3af/mcp/token/revoke',
        'target' => McpServerController::class . '::revokeTokenAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_token_revoke_all' => [
        'path' => '/nst3af/mcp/token/revoke-all',
        'target' => McpServerController::class . '::revokeAllAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_mcp_remote_token_issue' => [
        'path' => '/nst3af/mcp/mcp-remote-token/issue',
        'target' => McpServerController::class . '::issueMcpRemoteTokenAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_client_token_issue' => [
        'path' => '/nst3af/mcp/client-token/issue',
        'target' => McpServerController::class . '::issueClientTokenAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_advanced_save' => [
        'path' => '/nst3af/mcp/advanced/save',
        'target' => McpServerController::class . '::saveAdvancedSettingsAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_workspace_create' => [
        'path' => '/nst3af/mcp/workspace/create',
        'target' => McpServerController::class . '::createWorkspaceAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_workspace_preference' => [
        'path' => '/nst3af/mcp/workspace/preference',
        'target' => McpServerController::class . '::saveWorkspacePreferenceAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_mode_save' => [
        'path' => '/nst3af/mcp/mode/save',
        'target' => McpServerController::class . '::saveMcpModeAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_security_scopes_save' => [
        'path' => '/nst3af/mcp/security/scopes/save',
        'target' => McpServerController::class . '::securityScopesSaveAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_ip_allowlist_add' => [
        'path' => '/nst3af/mcp/security/ip-allowlist/add',
        'target' => McpServerController::class . '::ipAllowlistAddAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_ip_allowlist_remove' => [
        'path' => '/nst3af/mcp/security/ip-allowlist/remove',
        'target' => McpServerController::class . '::ipAllowlistRemoveAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_ip_allowlist_toggle' => [
        'path' => '/nst3af/mcp/security/ip-allowlist/toggle',
        'target' => McpServerController::class . '::ipAllowlistToggleAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_mtls_save' => [
        'path' => '/nst3af/mcp/security/mtls/save',
        'target' => McpServerController::class . '::mtlsSaveAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_token_list' => [
        'path' => '/nst3af/mcp/token/list',
        'target' => McpServerController::class . '::tokenListAction',
    ],
    'nst3af_mcp_analytics_export' => [
        'path' => '/nst3af/mcp/analytics/export',
        'target' => McpServerController::class . '::analyticsExportAction',
    ],
    'nst3af_mcp_health_ping_all' => [
        'path' => '/nst3af/mcp/health/ping-all',
        'target' => McpServerController::class . '::healthPingAllAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_discover' => [
        'path' => '/nst3af/mcp/tools/discover',
        'target' => McpToolsController::class . '::discoverAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_toggle' => [
        'path' => '/nst3af/mcp/tools/toggle',
        'target' => McpToolsController::class . '::toggleTableAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_save' => [
        'path' => '/nst3af/mcp/tools/save',
        'target' => McpToolsController::class . '::saveTableAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_playground_invoke' => [
        'path' => '/nst3af/mcp/tools/playground/invoke',
        'target' => McpToolsController::class . '::playgroundInvokeAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_playground_tool_stats' => [
        'path' => '/nst3af/mcp/tools/playground/tool-stats',
        'target' => McpToolsController::class . '::playgroundToolStatsAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_prompt_create' => [
        'path' => '/nst3af/mcp/tools/prompt/create',
        'target' => McpToolsController::class . '::promptCreateAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_prompt_update' => [
        'path' => '/nst3af/mcp/tools/prompt/update',
        'target' => McpToolsController::class . '::promptUpdateAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_prompt_delete' => [
        'path' => '/nst3af/mcp/tools/prompt/delete',
        'target' => McpToolsController::class . '::promptDeleteAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_custom_create' => [
        'path' => '/nst3af/mcp/tools/custom/create',
        'target' => McpToolsController::class . '::customToolCreateAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_custom_update' => [
        'path' => '/nst3af/mcp/tools/custom/update',
        'target' => McpToolsController::class . '::customToolUpdateAction',
    ],
    'nst3af_mcp_tools_custom_delete' => [
        'path' => '/nst3af/mcp/tools/custom/delete',
        'target' => McpToolsController::class . '::customToolDeleteAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_skill_import' => [
        'path' => '/nst3af/mcp/tools/skill/import',
        'target' => McpToolsController::class . '::skillImportAction',
        'methods' => ['POST'],
    ],
    'nst3af_mcp_tools_skill_remove' => [
        'path' => '/nst3af/mcp/tools/skill/remove',
        'target' => McpToolsController::class . '::skillRemoveAction',
        'methods' => ['POST'],
    ],
    'nst3af_feature_settings_get' => [
        'path' => '/nst3af/feature-settings/get',
        'target' => FeatureSettingsController::class . '::getAction',
    ],
    'nst3af_feature_settings_save' => [
        'path' => '/nst3af/feature-settings/save',
        'target' => FeatureSettingsController::class . '::saveAction',
        'methods' => ['POST'],
    ],
    'nst3af_brand_context_research' => [
        'path' => '/nst3af/brand-context/research',
        'target' => BrandContextAjaxController::class . '::researchAction',
        'methods' => ['POST'],
    ],
    'nst3af_brand_context_extract_documents' => [
        'path' => '/nst3af/brand-context/extract-documents',
        'target' => BrandContextAjaxController::class . '::extractDocumentsAction',
        'methods' => ['POST'],
    ],
    'nst3af_access_roles_groups' => [
        'path' => '/nst3af/access-roles/groups',
        'target' => AccessRolesAjaxController::class . '::groupsAction',
    ],
    'nst3af_access_roles_group' => [
        'path' => '/nst3af/access-roles/group',
        'target' => AccessRolesAjaxController::class . '::groupAction',
    ],
    'nst3af_access_roles_apply' => [
        'path' => '/nst3af/access-roles/apply',
        'target' => AccessRolesAjaxController::class . '::applyAction',
        'methods' => ['POST'],
    ],
    'nst3af_access_roles_matrix' => [
        'path' => '/nst3af/access-roles/matrix',
        'target' => AccessRolesAjaxController::class . '::matrixAction',
    ],
    'nst3af_access_roles_preview' => [
        'path' => '/nst3af/access-roles/preview',
        'target' => AccessRolesAjaxController::class . '::previewAction',
        'methods' => ['POST'],
    ],
];
