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

namespace NITSAN\NsT3AF\Tests\Unit\Access;

use NITSAN\NsT3Aa\Access\T3AaLegacyCustomOptionExpander;
use NITSAN\NsT3AF\Access\Dto\GroupConfig;
use NITSAN\NsT3AF\Access\Dto\LimitsConfig;
use NITSAN\NsT3AF\Access\Enum\FeatureLevel;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class T3AaLegacyCustomOptionExpanderTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    #[Test]
    public function expandsDashboardWhenModuleEnabledWithoutFeatures(): void
    {
        if (!class_exists(\NITSAN\NsT3AA\Utility\PermissionUtility::class)) {
            self::markTestSkipped('ns_t3aa is not available in this test environment.');
        }

        $modules = $this->defaultGroupModules();
        $modules['t3aa'] = true;

        $config = new GroupConfig(
            modules: $modules,
            features: $this->defaultGroupFeatures(),
            records: $this->defaultGroupRecords(),
            limits: new LimitsConfig(),
        );

        $options = (new T3AaLegacyCustomOptionExpander())->expandForConfig($config);

        self::assertContains('tx_t3aa_dashboard:dashboard', $options);
    }

    #[Test]
    public function expandsEnabledCardKeys(): void
    {
        if (!class_exists(\NITSAN\NsT3AA\Utility\PermissionUtility::class)) {
            self::markTestSkipped('ns_t3aa is not available in this test environment.');
        }

        $modules = $this->defaultGroupModules();
        $modules['t3aa'] = true;

        $features = $this->defaultGroupFeatures();
        $features['t3aaFileMeta'] = FeatureLevel::Manage->value;

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $this->defaultGroupRecords(),
            limits: new LimitsConfig(),
        );

        $options = (new T3AaLegacyCustomOptionExpander())->expandForConfig($config);

        self::assertContains('tx_t3aa_dashboard:aiFileMetaVision', $options);
        self::assertContains('tx_t3aa_dashboard:aiFileMetaAltText', $options);
    }
}
