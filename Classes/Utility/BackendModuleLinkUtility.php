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

namespace NITSAN\NsT3AF\Utility;

use NITSAN\NsT3AF\Access\ModuleTabAccessService;
use NITSAN\NsT3AF\Service\DashboardPeriodResolver;
use NITSAN\NsT3AF\Service\SetupChecklistPresenter;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module deep links for child extensions (AI Logs, …).
 */
final class BackendModuleLinkUtility
{
    public const ROUTE_DASHBOARD = 't3af_dashboard.overview';
    public const ROUTE_AI_USAGE = 't3af_dashboard.ai_usage';
    public const ROUTE_AI_LOGS = 't3af_dashboard.ai_logs';
    public const ROUTE_PROVIDERS = 't3af_dashboard.providers';

    private const LOCALLANG_MOD = 'EXT:ns_t3af/Resources/Private/Language/locallang_mod.xlf';

    /**
     * AI Foundation → Dashboard overview with optional page-tree id.
     */
    public static function buildDashboardUri(int $pageId = 0): string
    {
        if (!ExtensionManagementUtility::isLoaded('ns_t3af')) {
            return '';
        }

        $parameters = [];
        if ($pageId > 0) {
            $parameters['id'] = $pageId;
        }

        return (string) GeneralUtility::makeInstance(UriBuilder::class)
            ->buildUriFromRoute(self::ROUTE_DASHBOARD, $parameters);
    }

    /**
     * AI Foundation → AI Logs with optional page-tree id and extension pre-filter.
     */
    public static function buildAiLogsUri(int $pageId = 0, string $extensionKey = ''): string
    {
        if (!ExtensionManagementUtility::isLoaded('ns_t3af')) {
            return '';
        }

        $parameters = [
            'period' => DashboardPeriodResolver::PRESET_7D,
        ];
        if ($pageId > 0) {
            $parameters['id'] = $pageId;
        }
        if ($extensionKey !== '') {
            $parameters['extension'] = $extensionKey;
        }

        return (string) GeneralUtility::makeInstance(UriBuilder::class)
            ->buildUriFromRoute(self::ROUTE_AI_LOGS, $parameters);
    }

    /**
     * AI Foundation → Providers with optional page-tree id.
     */
    public static function buildProvidersUri(int $pageId = 0): string
    {
        if (!ExtensionManagementUtility::isLoaded('ns_t3af')) {
            return '';
        }

        $parameters = [];
        if ($pageId > 0) {
            $parameters['id'] = $pageId;
        }

        return (string) GeneralUtility::makeInstance(UriBuilder::class)
            ->buildUriFromRoute(self::ROUTE_PROVIDERS, $parameters);
    }

    /**
     * AI Foundation → AI Usage with optional page-tree id.
     */
    public static function buildAiUsageUri(int $pageId = 0): string
    {
        if (!ExtensionManagementUtility::isLoaded('ns_t3af')) {
            return '';
        }

        $parameters = [
            'period' => DashboardPeriodResolver::PRESET_7D,
        ];
        if ($pageId > 0) {
            $parameters['id'] = $pageId;
        }

        return (string) GeneralUtility::makeInstance(UriBuilder::class)
            ->buildUriFromRoute(self::ROUTE_AI_USAGE, $parameters);
    }

    /**
     * Utility navigation tabs for child module docheaders (AI Usage · AI Logs).
     *
     * @return list<array{title: string, href: string, active: int}>
     */
    public static function buildUtilityTabs(int $pageId = 0, string $extensionKey = ''): array
    {
        if (!ExtensionManagementUtility::isLoaded('ns_t3af')) {
            return [];
        }

        $accessService = GeneralUtility::makeInstance(ModuleTabAccessService::class);
        $backendUser = self::resolveBackendUser();
        $tabs = [];

        if ($accessService->isTabVisible('aiUsage', $backendUser)) {
            $tabs[] = [
                'title' => self::translateModule('module.menu.aiUsage'),
                'href' => self::buildAiUsageUri($pageId),
                'active' => 0,
            ];
        }

        if ($accessService->isTabVisible('aiLogs', $backendUser)) {
            $tabs[] = [
                'title' => self::translateModule('module.menu.aiLogs'),
                'href' => self::buildAiLogsUri($pageId, $extensionKey),
                'active' => 0,
            ];
        }

        return $tabs;
    }

    /**
     * View assigns for child extension docheader aside (utility dropdown + AI Foundation link).
     *
     * @return array{
     *   utilityTabs: list<array{title: string, href: string, active: int}>,
     *   aiFoundationUri: string,
     *   showAiFoundationLink: int,
     *   aiUniverseLogsUri: string
     * }
     */
    public static function buildChildDocHeaderAsideAssigns(int $pageId = 0, string $extensionKey = ''): array
    {
        $presenter = GeneralUtility::makeInstance(SetupChecklistPresenter::class);
        if (method_exists($presenter, 'buildChildDocHeaderAsideAssigns')) {
            return $presenter->buildChildDocHeaderAsideAssigns($pageId, $extensionKey);
        }

        if (!ExtensionManagementUtility::isLoaded('ns_t3af')) {
            return [
                'utilityTabs' => [],
                'aiFoundationUri' => '',
                'showAiFoundationLink' => 0,
                'aiUniverseLogsUri' => '',
            ];
        }

        $accessService = GeneralUtility::makeInstance(ModuleTabAccessService::class);
        $backendUser = self::resolveBackendUser();
        $aiFoundationUri = $accessService->isTabVisible('dashboard', $backendUser)
            ? self::buildDashboardUri($pageId)
            : '';

        return [
            'utilityTabs' => self::buildUtilityTabs($pageId, $extensionKey),
            'aiFoundationUri' => $aiFoundationUri,
            'showAiFoundationLink' => $aiFoundationUri !== '' ? 1 : 0,
            'aiUniverseLogsUri' => $aiFoundationUri,
        ];
    }

    private static function resolveBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    private static function translateModule(string $key): string
    {
        return (string) ($GLOBALS['LANG']?->sL('LLL:' . self::LOCALLANG_MOD . ':' . $key) ?? $key);
    }
}
