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

namespace NITSAN\NsT3AF\Tests\Unit\Registry;

use NITSAN\NsT3AF\Access\Dto\FeatureAccessBindingsDescriptor;
use NITSAN\NsT3AF\Access\Dto\FeaturePermissionDescriptor;
use NITSAN\NsT3AF\Access\Dto\ModuleAccessDescriptor;
use NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface;
use NITSAN\NsT3AF\Registry\AiAccessCatalogProviderRegistry;
use PHPUnit\Framework\TestCase;

final class AiAccessCatalogProviderRegistryTest extends TestCase
{
    public function testSkipsUnavailableProviders(): void
    {
        $available = $this->createMock(AiAccessCatalogProviderInterface::class);
        $available->method('isAvailable')->willReturn(true);
        $available->method('getExtensionKey')->willReturn('ns_example');
        $available->method('getFeaturePermissions')->willReturn([
            new FeaturePermissionDescriptor('demo', 'Demo', 'Demo feature', 'Demo.Feature', ['demo'], 'demo', 'level', 'ns_example'),
        ]);

        $unavailable = $this->createMock(AiAccessCatalogProviderInterface::class);
        $unavailable->method('isAvailable')->willReturn(false);

        $registry = new AiAccessCatalogProviderRegistry([$available, $unavailable]);

        self::assertCount(1, $registry->getAvailableProviders());
        self::assertSame('Demo.Feature', $registry->getFeaturePermissions()[0]->permBase);
    }

    public function testProviderModuleAccessOverridesByCatalogKey(): void
    {
        $provider = $this->createMock(AiAccessCatalogProviderInterface::class);
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('getCatalogModuleKey')->willReturn('t3ai');
        $provider->method('getModuleAccess')->willReturn(new ModuleAccessDescriptor(
            'Custom T3AI',
            'Label',
            'Description',
            '#000000',
            'custom_group_mod',
            'ns_custom',
        ));

        $registry = new AiAccessCatalogProviderRegistry([$provider]);

        self::assertSame('Custom T3AI', $registry->getModuleAccessByKey()['t3ai']->label);
    }

    public function testNullFeatureAccessBindingsAreSkipped(): void
    {
        $owner = $this->createMock(AiAccessCatalogProviderInterface::class);
        $owner->method('isAvailable')->willReturn(true);
        $owner->method('getFeatureAccessBindings')->willReturn(new FeatureAccessBindingsDescriptor(
            moduleKey: 't3cs',
            legacyCardPermPrefix: 'tx_t3cs_',
            moduleGroupMod: 'nitsan_nst3cs_t3cs',
            suiteTabFeatureMap: ['Search' => 'T3CS.Search'],
        ));

        $child = $this->createMock(AiAccessCatalogProviderInterface::class);
        $child->method('isAvailable')->willReturn(true);
        $child->method('getFeatureAccessBindings')->willReturn(null);

        $registry = new AiAccessCatalogProviderRegistry([$owner, $child]);

        self::assertSame('T3CS.Search', $registry->getFeatureAccessBindingsByModuleKey()['t3cs']->suiteTabFeatureMap['Search']);
    }

    public function testEmptyChildStubDoesNotOverwriteSuiteTabMap(): void
    {
        $owner = $this->createMock(AiAccessCatalogProviderInterface::class);
        $owner->method('isAvailable')->willReturn(true);
        $owner->method('getFeatureAccessBindings')->willReturn(new FeatureAccessBindingsDescriptor(
            moduleKey: 't3cs',
            legacyCardPermPrefix: 'tx_t3cs_',
            moduleGroupMod: 'nitsan_nst3cs_t3cs',
            suiteTabFeatureMap: ['Dashboard' => 'T3CS.Index'],
            openWhenNoFeatureBits: true,
            featureBitPrefix: 'T3CS.',
        ));

        $childStub = $this->createMock(AiAccessCatalogProviderInterface::class);
        $childStub->method('isAvailable')->willReturn(true);
        $childStub->method('getFeatureAccessBindings')->willReturn(new FeatureAccessBindingsDescriptor(
            moduleKey: 't3cs',
            legacyCardPermPrefix: 'tx_t3cs_',
        ));

        $registry = new AiAccessCatalogProviderRegistry([$owner, $childStub]);

        $bindings = $registry->getFeatureAccessBindingsByModuleKey()['t3cs'];
        self::assertSame('T3CS.Index', $bindings->suiteTabFeatureMap['Dashboard']);
        self::assertTrue($bindings->openWhenNoFeatureBits);
        self::assertSame('nitsan_nst3cs_t3cs', $bindings->moduleGroupMod);
    }
}
