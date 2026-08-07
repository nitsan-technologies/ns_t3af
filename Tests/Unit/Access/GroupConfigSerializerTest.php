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
use NITSAN\NsT3AF\Access\Dto\LimitsConfig;
use NITSAN\NsT3AF\Access\Enum\FeatureLevel;
use NITSAN\NsT3AF\Access\GroupConfigSerializer;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupConfigSerializerTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    private GroupConfigSerializer $serializer;

    protected function setUp(): void
    {
        $this->mockAllCatalogExtensionsLoaded();
        $this->serializer = $this->createGroupConfigSerializer();
    }

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    #[Test]
    public function consumerPresetWritesExpectedBits(): void
    {
        $registry = $this->createGroupPresetRegistry();
        $config = $registry->build('consumer');

        $serialized = $this->serializer->serialize($config);

        self::assertContains('nitsan_nst3ai_dashboard', $serialized['groupMods']);
        self::assertContains('nst3af_tab:ai_prompts', $serialized['customOptions']);
        self::assertContains('T3Ai:Content', $serialized['customOptions']);
        self::assertContains('tx_nst3af_ai_prompt', $serialized['tablesSelect']);
        self::assertNotContains('tx_nst3af_ai_prompt', $serialized['tablesModify']);
    }

    #[Test]
    public function editorPresetWritesReadWriteForPrompts(): void
    {
        $registry = $this->createGroupPresetRegistry();
        $config = $registry->build('editor');

        $serialized = $this->serializer->serialize($config);

        self::assertContains('T3Ai:Content.Manage', $serialized['customOptions']);
        self::assertContains('T3Ai:Pages', $serialized['customOptions']);
        self::assertContains('tx_nst3af_ai_prompt', $serialized['tablesModify']);
    }

    #[Test]
    public function childModuleOnlyWritesShellGroupMods(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3ai'] = true;

        $config = new GroupConfig(
            modules: $modules,
            features: $this->defaultGroupFeatures(),
            records: $this->defaultGroupRecords(),
            limits: new LimitsConfig(),
        );

        $serialized = $this->serializer->serialize($config);

        self::assertContains('t3af', $serialized['groupMods']);
        self::assertContains('t3af_dashboard', $serialized['groupMods']);
        self::assertContains('nitsan_nst3ai_dashboard', $serialized['groupMods']);
        self::assertContains('nst3af:capability_chat', $serialized['customOptions']);
    }

    #[Test]
    public function translationFeatureExpandsLegacyT3AiCustomOptionsWhenAvailable(): void
    {
        if (!class_exists(\NITSAN\NsT3Ai\Utility\NsT3AiPermissionUtility::class)) {
            self::markTestSkipped('ns_t3ai is not available in this test environment.');
        }

        $modules = $this->defaultGroupModules();
        $modules['t3ai'] = true;

        $features = $this->defaultGroupFeatures();
        $features['translation'] = FeatureLevel::Use->value;

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $this->defaultGroupRecords(),
            limits: new LimitsConfig(),
        );

        $serialized = $this->serializer->serialize($config);

        self::assertContains('T3Ai:Translation', $serialized['customOptions']);
        self::assertContains('tx_t3ai_translation:translation', $serialized['customOptions']);
        self::assertContains('tx_t3ai_translation:translationGlossary', $serialized['customOptions']);
    }

    #[Test]
    public function translationManageWritesManageBitAndRoundTrips(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3ai'] = true;

        $features = $this->defaultGroupFeatures();
        $features['translation'] = FeatureLevel::Manage->value;

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $this->defaultGroupRecords(),
            limits: new LimitsConfig(),
        );

        $normalizer = $this->createGroupConfigNormalizer();
        $config = $normalizer->normalize($config);
        $serialized = $this->serializer->serialize($config);

        self::assertContains('T3Ai:Translation.Manage', $serialized['customOptions']);
        self::assertNotContains('T3Ai:Translation', $serialized['customOptions']);
        self::assertContains('tt_content', $serialized['tablesSelect']);
        self::assertContains('tt_content', $serialized['tablesModify']);

        $deserializer = $this->createGroupConfigDeserializer();
        $restored = $deserializer->deserialize([
            'groupMods' => implode(',', $serialized['groupMods']),
            'custom_options' => implode(',', $serialized['customOptions']),
            'tables_select' => implode(',', $serialized['tablesSelect']),
            'tables_modify' => implode(',', $serialized['tablesModify']),
        ]);

        self::assertSame(FeatureLevel::Manage->value, $restored->features['translation']);
    }

    #[Test]
    public function t3aaFileMetaManageWritesManageBitAndLegacyDashboardCards(): void
    {
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

        $normalizer = $this->createGroupConfigNormalizer();
        $config = $normalizer->normalize($config);
        $serialized = $this->serializer->serialize($config);

        self::assertContains('T3Ai:T3AA', $serialized['customOptions']);
        self::assertContains('T3Ai:T3AA.FileMeta.Manage', $serialized['customOptions']);
        self::assertNotContains('T3Ai:T3AA.FileMeta', $serialized['customOptions']);

        if (class_exists(\NITSAN\NsT3AA\Utility\PermissionUtility::class)) {
            self::assertContains('tx_t3aa_dashboard:aiFileMetaVision', $serialized['customOptions']);
            self::assertNotContains('tx_t3aa_dashboard:seoSpeedCore', $serialized['customOptions']);
        }

        self::assertContains('sys_file', $serialized['tablesSelect']);
        self::assertContains('sys_file_metadata', $serialized['tablesSelect']);
        self::assertContains('sys_file_metadata', $serialized['tablesModify']);
        self::assertContains('tx_nst3aa_domain_model_bulkmeta', $serialized['tablesModify']);

        $deserializer = $this->createGroupConfigDeserializer();
        $restored = $deserializer->deserialize([
            'groupMods' => implode(',', $serialized['groupMods']),
            'custom_options' => implode(',', $serialized['customOptions']),
            'tables_select' => implode(',', $serialized['tablesSelect']),
            'tables_modify' => implode(',', $serialized['tablesModify']),
        ]);

        self::assertSame(FeatureLevel::Manage->value, $restored->features['t3aaFileMeta']);
    }

    #[Test]
    public function legacyT3AaScanBitHydratesNewFeatureGroups(): void
    {
        $deserializer = $this->createGroupConfigDeserializer();
        $restored = $deserializer->deserialize([
            'groupMods' => 'nitsan_nst3aa_dashboard',
            'custom_options' => 'T3Ai:T3AA,T3Ai:T3AA.Scan.Manage',
            'tables_select' => '',
            'tables_modify' => '',
        ]);

        self::assertSame(FeatureLevel::Manage->value, $restored->features['t3aaPageSpeed']);
        self::assertSame(FeatureLevel::Manage->value, $restored->features['t3aaFileMeta']);
        self::assertSame(FeatureLevel::Manage->value, $restored->features['t3aaMedia']);
    }
}
