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

use NITSAN\NsT3AF\Access\Dto\GroupConfig;
use NITSAN\NsT3AF\Access\Enum\FeatureLevel;
use NITSAN\NsT3AF\Access\PayloadBoolean;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PayloadBooleanTest extends TestCase
{
    #[Test]
    public function parseHandlesFormDataFalseStrings(): void
    {
        self::assertFalse(PayloadBoolean::parse('false'));
        self::assertFalse(PayloadBoolean::parse('0'));
        self::assertFalse(PayloadBoolean::parse('off'));
        self::assertFalse(PayloadBoolean::parse('no'));
        self::assertTrue(PayloadBoolean::parse('true'));
        self::assertTrue(PayloadBoolean::parse('1'));
        self::assertTrue(PayloadBoolean::parse('on'));
        self::assertTrue(PayloadBoolean::parse(true));
        self::assertFalse(PayloadBoolean::parse(false));
    }
}

final class GroupConfigFromArrayTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    protected function setUp(): void
    {
        $this->mockAllCatalogExtensionsLoaded();
    }

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    #[Test]
    public function fromArrayDoesNotTreatFalseStringAsEnabledModule(): void
    {
        $config = GroupConfig::fromArray([
            'modules' => [
                't3cs' => true,
                'providers' => 'true',
                'aiUsage' => 'true',
                'aiLogs' => 'true',
                't3ai' => 'false',
                't3aa' => 'false',
                'mcpServer' => 'false',
                'mcpTools' => 'false',
                'aiFeatures' => 'false',
                'aiPrompts' => 'false',
                'schedulerCli' => 'false',
                'aiContext' => 'false',
            ],
            'features' => $this->defaultGroupFeatures(),
            'records' => $this->defaultGroupRecords(),
        ]);

        self::assertTrue($config->modules['t3cs']);
        self::assertTrue($config->modules['providers']);
        self::assertTrue($config->modules['aiUsage']);
        self::assertTrue($config->modules['aiLogs']);
        self::assertFalse($config->modules['t3ai']);
        self::assertFalse($config->modules['mcpServer']);
    }

    #[Test]
    public function narrowedSelectionSerializesOnlySelectedTabPermissions(): void
    {
        $modules = $this->defaultGroupModules();
        foreach ($modules as $key => $_) {
            $modules[$key] = false;
        }
        $modules['t3cs'] = true;
        $modules['providers'] = true;
        $modules['aiUsage'] = true;
        $modules['aiLogs'] = true;

        $features = $this->defaultGroupFeatures();
        $features['t3csChat'] = FeatureLevel::Use->value;
        $features['t3csIndex'] = FeatureLevel::Use->value;
        $features['t3csAnalytics'] = FeatureLevel::Use->value;

        $payload = GroupConfig::fromArray([
            'modules' => array_map(
                static fn(bool $enabled): string => $enabled ? 'true' : 'false',
                $modules,
            ),
            'features' => $features,
            'records' => $this->defaultGroupRecords(),
        ]);

        $normalizer = $this->createGroupConfigNormalizer();
        $serializer = $this->createGroupConfigSerializer();

        $serialized = $serializer->serialize($normalizer->normalize($payload));

        self::assertContains('nst3af_tab:providers', $serialized['customOptions']);
        self::assertContains('nst3af_tab:ai_usage', $serialized['customOptions']);
        self::assertContains('nst3af_tab:ai_logs', $serialized['customOptions']);
        self::assertNotContains('nst3af_tab:mcp_server', $serialized['customOptions']);
        self::assertNotContains('nst3af_tab:ai_prompts', $serialized['customOptions']);
        self::assertNotContains('nitsan_nst3ai_dashboard', $serialized['groupMods']);
        self::assertNotContains('nitsan_nst3aa_dashboard', $serialized['groupMods']);
        self::assertContains('nitsan_nst3cs_t3cs', $serialized['groupMods']);
        self::assertContains('t3af_dashboard', $serialized['groupMods']);
    }
}
