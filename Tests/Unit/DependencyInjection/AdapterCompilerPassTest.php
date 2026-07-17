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

namespace NITSAN\NsT3AF\Tests\Unit\DependencyInjection;

use NITSAN\NsT3AF\DependencyInjection\AdapterCompilerPass;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiPlatformDiscovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Guards the DI wiring between discovery and the adapter service.
 *
 * Regression: the pass once baked the BridgeDescriptor with only 6 scalar args,
 * dropping factoryClass/catalogClass. In classic (phar) mode that left the
 * adapter with a null factoryClass, forcing it to derive the bridge FQN from the
 * vendor key — which fails for case-folded bridges (openai -> Openai vs the
 * phar-scoped OpenAi) under the phar's case-sensitive classmap, surfacing as
 * "Symfony AI Platform runtime is not installed" for OpenAI only.
 *
 * @internal
 */
final class AdapterCompilerPassTest extends TestCase
{
    public function testDescriptorDefinitionForwardsFactoryAndCatalogClass(): void
    {
        // Real discovery, fed one installed Symfony AI package. SymfonyAiPlatformDiscovery
        // is final, so we drive it via its package-provider seam rather than subclassing.
        $discovery = new SymfonyAiPlatformDiscovery(
            static fn(): array => ['symfony/ai-open-ai-platform'],
        );

        $container = new ContainerBuilder();
        (new AdapterCompilerPass($discovery))->process($container);

        $descriptorId = 'nst3af.adapter.symfony_openai.descriptor';
        self::assertTrue($container->hasDefinition($descriptorId), 'descriptor service not registered');

        $args = $container->getDefinition($descriptorId)->getArguments();

        // 8 positional args: packageName, vendorKey, type, displayName, endpoint, caps,
        // factoryClass, catalogClass. The bug baked only the first 6.
        self::assertCount(
            8,
            $args,
            'descriptor definition dropped factoryClass/catalogClass — adapter cannot resolve scoped factories',
        );
        // factoryClass/catalogClass occupy positions 6 and 7 (here null: no phar loaded in
        // a unit test, so discovery uses the package path). Their presence is the guard.
        self::assertArrayHasKey(6, $args, 'factoryClass argument missing');
        self::assertArrayHasKey(7, $args, 'catalogClass argument missing');
    }
}
