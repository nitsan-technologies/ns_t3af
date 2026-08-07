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

use NITSAN\NsT3AF\Service\SiteStorageContextFactory;
use NITSAN\NsT3AF\Settings\ExtensionSettingsBootstrapReader;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;

/**
 * AiUniverseUtilityHelper
 *
 * Utility helper class providing common functionality for AI extensions
 */
class AiUniverseUtilityHelper
{
    private static bool $loadingExtensionConf = false;

    /**
     * Get TYPO3 version data as array
     *
     * @return array<string, mixed>
     */
    public static function getVersionData(): array
    {
        return VersionNumberUtility::convertVersionStringToArray(
            VersionNumberUtility::getCurrentTypo3Version(),
        );
    }

    /**
     * Get current language ID from module data
     *
     * @return int
     */
    public static function getLanguageId(): int
    {
        $moduleData = BackendUtility::getModuleData(['language'], [], 'web_layout');
        if (isset($moduleData['language'])) {
            return (int) $moduleData['language'];
        }
        return 0;
    }

    /**
     * Get current page record
     *
     * @param int $pageId
     * @param int $languageId
     * @return array<string, mixed>|null
     */
    public static function getCurrentPage(int $pageId, int $languageId): ?array
    {
        $currentPage = null;
        if ($pageId > 0) {
            if ($languageId === 0) {
                $currentPage = BackendUtility::getRecord(
                    'pages',
                    $pageId,
                );
            } elseif ($languageId > 0) {
                $overlayRecords = BackendUtility::getRecordLocalization(
                    'pages',
                    $pageId,
                    $languageId,
                );

                if (is_array($overlayRecords) && array_key_exists(0, $overlayRecords) && is_array($overlayRecords[0])) {
                    $currentPage = $overlayRecords[0];
                }
            }
        }
        return $currentPage;
    }

    /**
     * Get extension configuration
     *
     * @param string $extensionKey Extension key (default: 'ns_t3af')
     * @return array<string, mixed>
     */
    public static function getExtensionConf(string $extensionKey = 'ns_t3af', ?int $pageId = null): array
    {
        if (self::$loadingExtensionConf || !self::isDependencyInjectionContainerAvailable()) {
            return ExtensionSettingsBootstrapReader::getDefaults($extensionKey);
        }

        self::$loadingExtensionConf = true;
        try {
            $service = GeneralUtility::makeInstance(ExtensionSettingsService::class);
            $storagePid = null;
            if ($pageId !== null && $pageId > 0) {
                $storagePid = SiteStorageContextFactory::get()
                    ->resolveStoragePidFromPageId($pageId);
            }

            return $service->getAll($extensionKey, $storagePid, $pageId);
        } catch (\Throwable) {
            return ExtensionSettingsBootstrapReader::getDefaults($extensionKey);
        } finally {
            self::$loadingExtensionConf = false;
        }
    }

    /**
     * Read extension settings without resolving a storage pid.
     *
     * For global, non page-bound features (e.g. the fileadmin File list) where no
     * page id is available in the request. Returns the configured values from any
     * pid, so settings work regardless of which site/pid they were saved on.
     *
     * @return array<string, mixed>
     */
    public static function getExtensionConfIgnorePid(string $extensionKey = 'ns_t3af'): array
    {
        if (self::$loadingExtensionConf || !self::isDependencyInjectionContainerAvailable()) {
            return ExtensionSettingsBootstrapReader::getDefaults($extensionKey);
        }

        self::$loadingExtensionConf = true;
        try {
            return GeneralUtility::makeInstance(ExtensionSettingsService::class)
                ->getAllIgnorePid($extensionKey);
        } catch (\Throwable) {
            return ExtensionSettingsBootstrapReader::getDefaults($extensionKey);
        } finally {
            self::$loadingExtensionConf = false;
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function setExtensionConf(array $value, string $extensionKey = 'ns_t3af', ?int $pageId = null): void
    {
        if (!self::isDependencyInjectionContainerAvailable()) {
            return;
        }

        $storagePid = null;
        if ($pageId !== null && $pageId > 0) {
            $storagePid = SiteStorageContextFactory::get()
                ->resolveStoragePidFromPageId($pageId);
        }

        GeneralUtility::makeInstance(ExtensionSettingsService::class)->replace($extensionKey, $value, $storagePid);
    }

    private static function isDependencyInjectionContainerAvailable(): bool
    {
        try {
            GeneralUtility::getContainer();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if API key is set for a given extension
     *
     * @param string $extensionKey Extension key (default: 'ns_t3af')
     * @param string $apiKeyName API key configuration name (default: 'openai_api_key')
     * @return bool
     */
    public static function isApiKeySet(string $extensionKey = 'ns_t3af', string $apiKeyName = 'openai_api_key'): bool
    {
        $extConf = self::getExtensionConf($extensionKey);
        return !empty($extConf[$apiKeyName]);
    }

    /**
     * Get TYPO3 major version
     *
     * @return int
     */
    public static function getTypo3MajorVersion(): int
    {
        $versionData = self::getVersionData();
        return (int) ($versionData['version_main'] ?? 11);
    }

    /**
     * Page-tree navigation component id for backend modules (v12–v14).
     *
     * v13.1+ / v14: @typo3/backend/tree/page-tree-element
     * v12: @typo3/backend/page-tree/page-tree-element
     *
     * @api
     */
    public static function getPageTreeNavigationComponent(): string
    {
        return self::getTypo3MajorVersion() >= 13
            ? '@typo3/backend/tree/page-tree-element'
            : '@typo3/backend/page-tree/page-tree-element';
    }

    /**
     * Check if an extension is loaded
     *
     * @param string $extensionKey Extension key to check
     * @return bool
     */
    public static function isExtensionLoaded(string $extensionKey): bool
    {
        return ExtensionManagementUtility::isLoaded($extensionKey);
    }
}
