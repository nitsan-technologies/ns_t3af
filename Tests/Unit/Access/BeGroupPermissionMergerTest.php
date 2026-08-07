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

use NITSAN\NsT3AF\Access\BeGroupPermissionMerger;
use NITSAN\NsT3AF\Access\Dto\GroupConfig;
use NITSAN\NsT3AF\Access\Enum\FeatureLevel;
use NITSAN\NsT3AF\Access\GroupConfigSerializer;
use NITSAN\NsT3AF\Access\RecordPermissionCatalog;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BeGroupPermissionMergerTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    private BeGroupPermissionMerger $merger;

    private GroupConfigSerializer $serializer;

    protected function setUp(): void
    {
        $this->mockAllCatalogExtensionsLoaded();
        $this->merger = new BeGroupPermissionMerger(
            $this->createRecordPermissionCatalog(),
            $this->createModuleAccessCatalog(),
            $this->createFeatureAccessBindingRegistry(),
        );
        $this->serializer = $this->createGroupConfigSerializer();
    }

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    #[Test]
    public function mergePreservesUnrelatedPermissions(): void
    {
        $recordCatalog = new RecordPermissionCatalog();
        $registry = $this->createGroupPresetRegistry();
        $normalizer = $this->createGroupConfigNormalizer();
        $config = $normalizer->normalize($registry->build('consumer'));
        $serialized = $this->serializer->serialize($config);

        $merged = $this->merger->merge([
            'groupMods' => 'web_list,pages,nitsan_nst3aa_dashboard',
            'custom_options' => 'nst3af:capability_chat,tx_t3ai_content:content,other_ext:foo',
            'tables_select' => 'pages,tt_content,tx_nst3af_provider',
            'tables_modify' => 'tt_content,pages',
        ], $serialized);

        self::assertStringContainsString('web_list', $merged['groupMods']);
        self::assertStringContainsString('pages', $merged['groupMods']);
        self::assertStringNotContainsString('nitsan_nst3aa_dashboard', $merged['groupMods']);
        self::assertStringContainsString('nitsan_nst3ai_dashboard', $merged['groupMods']);

        self::assertStringContainsString('nst3af:capability_chat', $merged['custom_options']);
        self::assertStringContainsString('other_ext:foo', $merged['custom_options']);
        self::assertStringNotContainsString('tx_t3ai_content:content', $merged['custom_options']);
        self::assertStringContainsString('T3Ai:Content', $merged['custom_options']);
        self::assertStringContainsString('nst3af_tab:ai_prompts', $merged['custom_options']);

        self::assertStringContainsString('pages', $merged['tables_select']);
        self::assertStringContainsString('tt_content', $merged['tables_select']);
        self::assertStringContainsString('tx_nst3af_ai_prompt', $merged['tables_select']);

        // Consumer preset grants pageContent read (tt_content select only); unrelated pages modify is preserved.
        self::assertSame('pages', $merged['tables_modify']);
    }

    #[Test]
    public function mergeReplacesPreviouslyManagedAiPermissions(): void
    {
        $modules = $this->defaultGroupModules();
        $modules['t3ai'] = true;
        $modules['aiPrompts'] = true;

        $config = new GroupConfig(
            modules: $modules,
            features: array_merge($this->defaultGroupFeatures(), [
                'content' => FeatureLevel::Use->value,
            ]),
            records: $this->defaultGroupRecords(),
        );
        $serialized = $this->serializer->serialize($config);

        $merged = $this->merger->merge([
            'groupMods' => 'nitsan_nst3ai_dashboard,nitsan_nst3aa_dashboard',
            'custom_options' => 'nst3af_tab:providers,T3Ai:SEO,tx_t3aa_dashboard:seoSpeedCore',
            'tables_select' => 'tx_nst3af_provider,tx_nst3af_ai_prompt',
            'tables_modify' => 'tx_nst3af_ai_prompt',
        ], $serialized);

        self::assertSame('nitsan_nst3ai_dashboard,t3af,t3af_dashboard', $merged['groupMods']);
        self::assertStringNotContainsString('nitsan_nst3aa_dashboard', $merged['groupMods']);

        self::assertStringNotContainsString('nst3af_tab:providers', $merged['custom_options']);
        self::assertStringNotContainsString('T3Ai:SEO', $merged['custom_options']);
        self::assertStringNotContainsString('tx_t3aa_dashboard:seoSpeedCore', $merged['custom_options']);
        self::assertStringContainsString('T3Ai:Content', $merged['custom_options']);
        self::assertStringContainsString('nst3af_tab:ai_prompts', $merged['custom_options']);

        self::assertStringNotContainsString('tx_nst3af_provider', $merged['tables_select']);
        self::assertStringNotContainsString('tx_nst3af_ai_prompt', $merged['tables_select']);
        self::assertSame('', $merged['tables_modify']);
    }

    #[Test]
    public function mergeRemovesManagedTablesWhenWizardSetsNone(): void
    {
        $registry = $this->createGroupPresetRegistry();
        $config = $registry->build('manager');
        foreach ($config->records as $recordId => $level) {
            $config->records[$recordId] = 'none';
        }
        $serialized = $this->serializer->serialize($config);

        $merged = $this->merger->merge([
            'groupMods' => 'web_list',
            'custom_options' => '',
            'tables_select' => 'pages,tx_nst3af_provider,tx_nst3af_ai_prompt',
            'tables_modify' => 'tx_nst3af_provider',
        ], $serialized);

        self::assertSame('pages', $merged['tables_select']);
        self::assertSame('', $merged['tables_modify']);
    }
}
