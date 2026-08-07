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

use NITSAN\NsT3AF\Contract\AiFeatureCardDescriptor;
use NITSAN\NsT3AF\Contract\AiFeatureCardProviderInterface;
use NITSAN\NsT3AF\Registry\AiFeatureCardProviderRegistry;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AiFeatureCardProviderRegistryTest extends TestCase
{
    public function testBuildCatalogSortsByPriorityAndFiltersByModuleAccess(): void
    {
        $registry = new AiFeatureCardProviderRegistry([
            new class implements AiFeatureCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_demo';
                }

                public function getFeatureCards(): array
                {
                    return [
                        new AiFeatureCardDescriptor(
                            id: 'demo-b',
                            name: 'B',
                            subtitle: 'B',
                            extKey: 'ns_demo',
                            settingsScope: 'scope-b',
                            icon: 'actions-check',
                            iconBg: 'bg',
                            iconColor: 'color',
                            tags: ['demo'],
                            sortPriority: 20,
                            requiredBackendModule: 'hidden_module',
                        ),
                        new AiFeatureCardDescriptor(
                            id: 'demo-a',
                            name: 'A',
                            subtitle: 'A',
                            extKey: 'ns_demo',
                            settingsScope: 'scope-a',
                            icon: 'actions-check',
                            iconBg: 'bg',
                            iconColor: 'color',
                            tags: ['demo'],
                            sortPriority: 10,
                        ),
                    ];
                }
            },
        ]);

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user['usergroup'] = '1';

        $catalog = $registry->buildCatalog($user);

        self::assertCount(1, $catalog);
        self::assertSame('demo-a', $catalog[0]['id']);
        self::assertSame('scope-a', $catalog[0]['settingsScope']);
    }

    public function testBuildCatalogIncludesT3CsDisplayKeysFromDescriptor(): void
    {
        $registry = new AiFeatureCardProviderRegistry([
            new class implements AiFeatureCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_t3cs';
                }

                public function getFeatureCards(): array
                {
                    return [
                        new AiFeatureCardDescriptor(
                            id: 't3cs-ai-engine',
                            name: 'Engine',
                            subtitle: 'Engine',
                            extKey: 'ns_t3cs',
                            settingsScope: 'ai engine',
                            icon: 'actions-check',
                            iconBg: 'bg',
                            iconColor: 'color',
                            tags: ['t3cs'],
                            displayExtKey: 'ns_t3as',
                            configExtKey: 'ns_t3cs',
                        ),
                    ];
                }
            },
        ]);

        $catalog = $registry->buildCatalog(null);

        self::assertSame('ns_t3as', $catalog[0]['displayExtKey']);
        self::assertSame('ns_t3cs', $catalog[0]['configExtKey']);
    }

    public function testCollectFilterExtensionKeysUsesDisplayKeyAndIgnoresModuleAccess(): void
    {
        $registry = new AiFeatureCardProviderRegistry([
            new class implements AiFeatureCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_t3ai';
                }

                public function getFeatureCards(): array
                {
                    return [
                        new AiFeatureCardDescriptor(
                            id: 'ai-content',
                            name: 'AI Content',
                            subtitle: 'Content',
                            extKey: 'ns_t3ai',
                            settingsScope: 'content',
                            icon: 'actions-check',
                            iconBg: 'bg',
                            iconColor: 'color',
                            tags: ['content'],
                            requiredBackendModule: 'hidden_module',
                        ),
                    ];
                }
            },
            new class implements AiFeatureCardProviderInterface {
                public function isAvailable(): bool
                {
                    return true;
                }

                public function getExtensionKey(): string
                {
                    return 'ns_t3cs';
                }

                public function getFeatureCards(): array
                {
                    return [
                        new AiFeatureCardDescriptor(
                            id: 't3cs-training',
                            name: 'Training',
                            subtitle: 'Training',
                            extKey: 'ns_t3cs',
                            settingsScope: 'training',
                            icon: 'actions-check',
                            iconBg: 'bg',
                            iconColor: 'color',
                            tags: ['t3cs'],
                            displayExtKey: 'ns_t3ac/ns_t3as',
                            configExtKey: 'ns_t3cs',
                            requiredBackendModule: 'hidden_module',
                        ),
                    ];
                }
            },
        ]);

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user['usergroup'] = '1';

        self::assertSame([], $registry->buildCatalog($user));
        self::assertEqualsCanonicalizing(['ns_t3ai', 'ns_t3ac/ns_t3as'], $registry->collectFilterExtensionKeys());
    }
}
