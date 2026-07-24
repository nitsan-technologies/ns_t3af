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

namespace NITSAN\NsT3AF\Tests\Unit\Settings;

use NITSAN\NsT3AF\Settings\ExtensionSettingsBootstrapReader;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExtensionSettingsBootstrapReaderTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ExtensionSettingsBootstrapReader::clearCache();
    }

    public function testGetDefaultsReturnsEmptyForUnknownExtension(): void
    {
        self::assertSame([], ExtensionSettingsBootstrapReader::getDefaults('not_a_real_extension_key_xyz'));
    }

    public function testGetDefaultsReadsNsT3aaFieldsTemplate(): void
    {
        try {
            ExtensionManagementUtility::extPath('ns_t3aa');
        } catch (\Throwable) {
            self::markTestSkipped('Extension environment is not bootstrapped');
        }

        $defaults = ExtensionSettingsBootstrapReader::getDefaults('ns_t3aa');

        self::assertSame('1', $defaults['enableLanguageTranslation'] ?? '');
        self::assertArrayHasKey('liveAuditCkEditor', $defaults);
        self::assertArrayHasKey('alttextApiKey', $defaults);
    }
}
