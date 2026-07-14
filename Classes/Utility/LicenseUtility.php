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

use NITSAN\NsLicense\Domain\Repository\NsLicenseRepository;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Local license checks for AI Foundation (no remote API calls).
 *
 * Standalone: valid free lifetime license on extension key {@see self::EXTENSION_KEY}.
 * Product shell: valid license on any loaded NITSAN product that registers
 * {@see self::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY} (e.g. ns_t3ai).
 * Third-party / sample extensions must not register there.
 */
final class LicenseUtility
{
    public const EXTENSION_KEY = 'ns_t3af';

    public const LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY = 'licenseDependentExtensions';

    public const REASON_OK = 'ok';

    public const REASON_NS_LICENSE_MISSING = 'ns_license_missing';

    public const REASON_NO_VALID_KEY = 'no_valid_key';

    /** @var (callable(string): bool)|null */
    private static $extensionLoadedChecker = null;

    /** @var (callable(string): (?array<string, mixed>))|null */
    private static $licenseDataFetcher = null;

    /**
     * @return array{valid: bool, reason: string}
     */
    public static function getModuleLicenseStatus(): array
    {
        if (!self::isExtensionLoaded('ns_license')) {
            return [
                'valid' => false,
                'reason' => self::REASON_NS_LICENSE_MISSING,
            ];
        }

        if (self::hasValidLicenseForExtension(self::EXTENSION_KEY)) {
            return [
                'valid' => true,
                'reason' => self::REASON_OK,
            ];
        }

        foreach (self::resolveLicenseDependentExtensionKeys() as $extensionKey) {
            if (
                self::isExtensionLoaded($extensionKey)
                && self::hasValidLicenseForExtension($extensionKey)
            ) {
                return [
                    'valid' => true,
                    'reason' => self::REASON_OK,
                ];
            }
        }

        return [
            'valid' => false,
            'reason' => self::REASON_NO_VALID_KEY,
        ];
    }

    public static function checkLicenseForModules(): bool
    {
        return self::getModuleLicenseStatus()['valid'];
    }

    /**
     * ViewHelper convention: true means hide restricted UI.
     */
    public static function checkLicenseForViewHelper(): bool
    {
        return !self::checkLicenseForModules();
    }

    /**
     * @return list<string>
     */
    public static function resolveLicenseDependentExtensionKeys(): array
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'][self::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY] ?? [];
        if (!is_array($configured)) {
            return [];
        }

        $keys = [];
        foreach ($configured as $extensionKey) {
            if (!is_string($extensionKey)) {
                continue;
            }
            $extensionKey = trim($extensionKey);
            if ($extensionKey !== '') {
                $keys[] = $extensionKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @internal Test seam only.
     *
     * @param (callable(string): bool)|null $checker
     */
    public static function setExtensionLoadedChecker(?callable $checker): void
    {
        self::$extensionLoadedChecker = $checker;
    }

    /**
     * @internal Test seam only.
     *
     * @param (callable(string): (?array<string, mixed>))|null $fetcher
     */
    public static function setLicenseDataFetcher(?callable $fetcher): void
    {
        self::$licenseDataFetcher = $fetcher;
    }

    private static function isExtensionLoaded(string $extensionKey): bool
    {
        if (self::$extensionLoadedChecker !== null) {
            return (bool) (self::$extensionLoadedChecker)($extensionKey);
        }

        return ExtensionManagementUtility::isLoaded($extensionKey);
    }

    private static function hasValidLicenseForExtension(string $extensionKey): bool
    {
        $licenseItem = self::fetchLicenseData($extensionKey);
        if ($licenseItem === null) {
            return false;
        }

        return self::validateLicenseDomain($licenseItem);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchLicenseData(string $extensionKey): ?array
    {
        if (self::$licenseDataFetcher !== null) {
            $result = (self::$licenseDataFetcher)($extensionKey);

            return is_array($result) ? $result : null;
        }

        try {
            $rows = GeneralUtility::makeInstance(NsLicenseRepository::class)->fetchData($extensionKey);
            if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
                return null;
            }

            return $rows[0];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $licenseItem
     */
    private static function validateLicenseDomain(array $licenseItem): bool
    {
        $licenseKey = (string) ($licenseItem['license_key'] ?? '');
        if ($licenseKey === '') {
            return false;
        }

        $orderId = (string) ($licenseItem['order_id'] ?? '');
        if ($orderId !== '' && str_starts_with($orderId, 'EXPIRED_')) {
            return false;
        }

        $domainsList = array_values(array_filter(
            array_map(
                static fn(string $domain): string => trim($domain),
                explode(',', implode(',', [
                    (string) ($licenseItem['local_domains'] ?? ''),
                    (string) ($licenseItem['staging_domains'] ?? ''),
                    (string) ($licenseItem['domains'] ?? ''),
                ])),
            ),
            static fn(string $domain): bool => $domain !== '',
        ));

        $hostName = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($hostName === '') {
            return false;
        }

        if (in_array($hostName, $domainsList, true)) {
            return true;
        }

        return !empty(array_filter(
            $domainsList,
            static fn(string $domain): bool
                => (str_ends_with($domain, '*') && str_starts_with($hostName, rtrim($domain, '*')))
                || (str_starts_with($domain, '*') && str_ends_with($hostName, ltrim($domain, '*'))),
        ));
    }
}
