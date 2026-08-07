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

namespace NITSAN\NsT3AF\Tests\Unit\Utility;

use NITSAN\NsT3AF\Utility\LicenseUtility;
use PHPUnit\Framework\TestCase;

final class LicenseUtilityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'][LicenseUtility::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY]);
        unset($_SERVER['HTTP_HOST']);
        LicenseUtility::setExtensionLoadedChecker(null);
        LicenseUtility::setLicenseDataFetcher(null);
        parent::tearDown();
    }

    public function testResolveLicenseDependentExtensionKeysReadsExtConf(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'][LicenseUtility::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY] = [
            'ns_t3ai',
            'ns_t3aa',
            'ns_t3ac',
            'ns_t3as',
            'ns_t3ai',
        ];

        $keys = LicenseUtility::resolveLicenseDependentExtensionKeys();

        self::assertSame(['ns_t3ai', 'ns_t3aa', 'ns_t3ac', 'ns_t3as'], $keys);
    }

    public function testExtensionKeyIsNsT3af(): void
    {
        self::assertSame('ns_t3af', LicenseUtility::EXTENSION_KEY);
    }

    public function testGetModuleLicenseStatusWhenNsLicenseMissing(): void
    {
        LicenseUtility::setExtensionLoadedChecker(static fn(string $key): bool => false);

        $status = LicenseUtility::getModuleLicenseStatus();

        self::assertFalse($status['valid']);
        self::assertSame(LicenseUtility::REASON_NS_LICENSE_MISSING, $status['reason']);
        self::assertFalse(LicenseUtility::checkLicenseForModules());
        self::assertTrue(LicenseUtility::checkLicenseForViewHelper());
    }

    public function testGetModuleLicenseStatusValidWithNsT3afKey(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';
        LicenseUtility::setExtensionLoadedChecker(static fn(string $key): bool => $key === 'ns_license');
        LicenseUtility::setLicenseDataFetcher(static function (string $key): ?array {
            if ($key !== 'ns_t3af') {
                return null;
            }

            return [
                'license_key' => 'FREE-LIFETIME',
                'domains' => 'example.test',
            ];
        });

        $status = LicenseUtility::getModuleLicenseStatus();

        self::assertTrue($status['valid']);
        self::assertSame(LicenseUtility::REASON_OK, $status['reason']);
    }

    public function testGetModuleLicenseStatusValidViaLicensedChildProduct(): void
    {
        $_SERVER['HTTP_HOST'] = 'shop.example.test';
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'][LicenseUtility::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY] = [
            'ns_t3ai',
        ];
        LicenseUtility::setExtensionLoadedChecker(static fn(string $key): bool => in_array($key, ['ns_license', 'ns_t3ai'], true));
        LicenseUtility::setLicenseDataFetcher(static function (string $key): ?array {
            if ($key === 'ns_t3ai') {
                return [
                    'license_key' => 'T3AI-KEY',
                    'domains' => 'shop.example.test',
                ];
            }

            return null;
        });

        $status = LicenseUtility::getModuleLicenseStatus();

        self::assertTrue($status['valid']);
        self::assertSame(LicenseUtility::REASON_OK, $status['reason']);
    }

    public function testGetModuleLicenseStatusInvalidWhenNoKeysMatch(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.test';
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'][LicenseUtility::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY] = [
            'ns_t3ai',
        ];
        LicenseUtility::setExtensionLoadedChecker(static fn(string $key): bool => in_array($key, ['ns_license', 'ns_t3ai'], true));
        LicenseUtility::setLicenseDataFetcher(static fn(string $key): ?array => null);

        $status = LicenseUtility::getModuleLicenseStatus();

        self::assertFalse($status['valid']);
        self::assertSame(LicenseUtility::REASON_NO_VALID_KEY, $status['reason']);
    }
}
