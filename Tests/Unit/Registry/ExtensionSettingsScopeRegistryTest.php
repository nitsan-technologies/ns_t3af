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

use NITSAN\NsT3AF\Contract\ExtensionSettingsScopeProviderInterface;
use NITSAN\NsT3AF\Registry\ExtensionSettingsScopeRegistry;
use PHPUnit\Framework\TestCase;

final class ExtensionSettingsScopeRegistryTest extends TestCase
{
    public function testResolvesCompositePaletteAndFieldFilterScopes(): void
    {
        $registry = new ExtensionSettingsScopeRegistry([
            new class implements ExtensionSettingsScopeProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_demo';
                }

                public function getAllowedScopes(): array
                {
                    return ['drawer', 'palette-tab', 'internal'];
                }

                public function getCompositeScopeCategories(): array
                {
                    return [
                        'drawer' => ['category-a', 'category-b'],
                    ];
                }

                public function getPaletteScopes(): array
                {
                    return [
                        'palette' => [
                            ['id' => 'tab', 'label' => 'Tab', 'scope' => 'palette-tab'],
                        ],
                    ];
                }

                public function getFieldFilterScopes(): array
                {
                    return [
                        'palette-tab' => [
                            ['category' => 'category-a', 'fields' => ['fieldOne']],
                        ],
                    ];
                }

                public function getSaveSuccessMessageKey(): string
                {
                    return 'module.aiFeatures.saveSuccess';
                }

                public function getUnavailableLabelKey(): string
                {
                    return 'module.aiFeatures.errorExtensionMissing';
                }
            },
        ]);

        self::assertTrue($registry->supportsExtension('ns_demo'));
        self::assertTrue($registry->isValidScope('ns_demo', 'palette-tab'));
        self::assertFalse($registry->isValidScope('ns_demo', 'missing'));
        self::assertSame(['category-a', 'category-b'], $registry->resolveCategoriesForScope('ns_demo', 'drawer'));
        self::assertTrue($registry->hasPaletteScope('ns_demo', 'palette'));
        self::assertCount(1, $registry->getPaletteDefinitions('ns_demo', 'palette'));
        self::assertCount(1, $registry->getFieldFilterDefinitions('ns_demo', 'palette-tab'));
    }
}
