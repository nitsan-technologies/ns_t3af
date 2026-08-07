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
use NITSAN\NsT3AF\Access\GroupConfigNormalizer;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupConfigNormalizerTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    private GroupConfigNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->mockAllCatalogExtensionsLoaded();
        $this->normalizer = $this->createGroupConfigNormalizer();
    }

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    #[Test]
    public function disablingModuleClearsOrphanFeaturesAndRecords(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3ai'] = false;
        $modules['t3cs'] = true;

        $features = $this->defaultGroupFeatures();
        $features['content'] = FeatureLevel::Manage->value;
        $features['t3csChat'] = FeatureLevel::Use->value;

        $records = $this->defaultGroupRecords();
        $records['aiPromptStorage'] = 'readwrite';
        $records['t3csChatbot'] = 'read';

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: new LimitsConfig(),
        );

        $normalized = $this->normalizer->normalize($config);

        self::assertSame(FeatureLevel::Disabled->value, $normalized->features['content']);
        self::assertSame(FeatureLevel::Use->value, $normalized->features['t3csChat']);
        self::assertSame('none', $normalized->records['aiPromptStorage']);
        self::assertSame('read', $normalized->records['t3csChatbot']);
    }

    #[Test]
    public function disablingFeatureClearsDependentRecords(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3cs'] = true;

        $features = $this->defaultGroupFeatures();
        $features['t3csChat'] = FeatureLevel::Disabled->value;
        $features['t3csIndex'] = FeatureLevel::Use->value;

        $records = $this->defaultGroupRecords();
        $records['t3csChatbot'] = 'read';
        $records['t3csDatasource'] = 'readwrite';

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: new LimitsConfig(),
        );

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('none', $normalized->records['t3csChatbot']);
        self::assertSame('readwrite', $normalized->records['t3csDatasource']);
    }

    #[Test]
    public function disablingSearchFeatureClearsSearchWidgetRecords(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3cs'] = true;

        $features = $this->defaultGroupFeatures();
        $features['t3csSearch'] = FeatureLevel::Disabled->value;
        $features['t3csIndex'] = FeatureLevel::Use->value;

        $records = $this->defaultGroupRecords();
        $records['t3csSearchSettings'] = 'read';
        $records['t3csPredefinedQuestions'] = 'readwrite';

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: new LimitsConfig(),
        );

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('none', $normalized->records['t3csSearchSettings']);
        self::assertSame('none', $normalized->records['t3csPredefinedQuestions']);
    }

    #[Test]
    public function legacyLogsFeatureMigratesToAnalytics(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3cs'] = true;

        $features = $this->defaultGroupFeatures();
        $features['t3csLogs'] = FeatureLevel::Use->value;

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $this->defaultGroupRecords(),
            limits: new LimitsConfig(),
        );

        $normalized = $this->normalizer->normalize($config);

        self::assertSame(FeatureLevel::Use->value, $normalized->features['t3csAnalytics']);
    }

    #[Test]
    public function disablingPromptsFeatureClearsPromptRecords(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3ai'] = true;

        $features = $this->defaultGroupFeatures();
        $features['prompts'] = FeatureLevel::Disabled->value;
        $features['content'] = FeatureLevel::Use->value;

        $records = $this->defaultGroupRecords();
        $records['aiPromptStorage'] = 'readwrite';
        $records['rteCommands'] = 'readwrite';

        $config = new GroupConfig(
            modules: $modules,
            features: $features,
            records: $records,
            limits: new LimitsConfig(),
        );

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('none', $normalized->records['aiPromptStorage']);
        self::assertSame('readwrite', $normalized->records['rteCommands']);
    }

    #[Test]
    public function translationUseAppliesGlossaryReadDefault(): void
    {
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

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('read', $normalized->records['translationGlossary']);
        self::assertSame('none', $normalized->records['bulkTranslation']);
    }

    #[Test]
    public function translationManageUpgradesGlossaryToReadWrite(): void
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

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('readwrite', $normalized->records['translationGlossary']);
    }

    #[Test]
    public function translationUseAppliesPageContentReadDefault(): void
    {
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

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('read', $normalized->records['pageContent']);
    }

    #[Test]
    public function translationManageAppliesPageContentReadWrite(): void
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

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('readwrite', $normalized->records['pageContent']);
    }

    #[Test]
    public function t3aaFileMetaManageAppliesFileMetadataAndBulkMetaDefaults(): void
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

        $normalized = $this->normalizer->normalize($config);

        self::assertSame('readwrite', $normalized->records['t3aaFileMetadata']);
        self::assertSame('readwrite', $normalized->records['t3aaBulkMeta']);
    }
}
