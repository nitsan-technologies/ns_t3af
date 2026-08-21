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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Utility;

use NITSAN\NsT3AF\Utility\LicenseUtility;
use PHPUnit\Framework\TestCase;

final class LicenseUtilityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'][LicenseUtility::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY]);
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

    public function testModuleLicenseGateAlwaysAllows(): void
    {
        $status = LicenseUtility::getModuleLicenseStatus();

        self::assertTrue($status['valid']);
        self::assertSame(LicenseUtility::REASON_OK, $status['reason']);
        self::assertTrue(LicenseUtility::checkLicenseForModules());
        self::assertFalse(LicenseUtility::checkLicenseForViewHelper());
    }
}
