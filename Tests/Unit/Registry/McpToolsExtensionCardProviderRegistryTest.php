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

use NITSAN\NsT3AF\Contract\McpToolsExtensionCardDescriptor;
use NITSAN\NsT3AF\Contract\McpToolsExtensionCardProviderInterface;
use NITSAN\NsT3AF\Registry\McpToolsExtensionCardProviderRegistry;
use PHPUnit\Framework\TestCase;

final class McpToolsExtensionCardProviderRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['extensions']);
        parent::tearDown();
    }

    public function testBuildExtensionConfigsPrefersProviderOverLegacy(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['extensions'] = [
            'ns_demo' => [
                'extensionKey' => 'ns_demo',
                'label' => 'Legacy Label',
                'toolPrefix' => 'legacy_',
            ],
        ];

        $registry = new McpToolsExtensionCardProviderRegistry([
            new class implements McpToolsExtensionCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_demo';
                }

                public function getCardDescriptor(): McpToolsExtensionCardDescriptor
                {
                    return new McpToolsExtensionCardDescriptor(
                        label: 'Provider Label',
                        toolPrefix: 'demo_',
                    );
                }
            },
        ]);

        $configs = $registry->buildExtensionConfigs();

        self::assertSame('Provider Label', $configs['ns_demo']['label']);
        self::assertSame('demo_', $configs['ns_demo']['toolPrefix']);
        self::assertSame('ns_demo', $configs['ns_demo']['extensionKey']);
    }

    public function testBuildExtensionConfigsFillsGapsFromLegacyCatalog(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['extensions'] = [
            'ns_legacy_only' => [
                'extensionKey' => 'ns_legacy_only',
                'label' => 'Legacy Only',
                'toolPrefix' => 'legacyonly_',
            ],
        ];

        $registry = new McpToolsExtensionCardProviderRegistry([]);

        $configs = $registry->buildExtensionConfigs();

        self::assertSame('Legacy Only', $configs['ns_legacy_only']['label']);
        self::assertSame('legacyonly_', $configs['ns_legacy_only']['toolPrefix']);
    }

    public function testHasProviderForExtension(): void
    {
        $registry = new McpToolsExtensionCardProviderRegistry([
            new class implements McpToolsExtensionCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_demo';
                }

                public function getCardDescriptor(): McpToolsExtensionCardDescriptor
                {
                    return new McpToolsExtensionCardDescriptor(label: 'Demo');
                }
            },
        ]);

        self::assertTrue($registry->hasProviderForExtension('ns_demo'));
        self::assertFalse($registry->hasProviderForExtension('ns_missing'));
    }
}
