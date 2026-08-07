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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\McpToolOwnershipResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpToolOwnershipResolverTest extends TestCase
{
    private McpToolOwnershipResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new McpToolOwnershipResolver();
    }

    #[Test]
    public function resolveUsesExplicitOwnerExtensionKeyFirst(): void
    {
        $owner = $this->resolver->resolve(
            [
                'name' => 'pages_list',
                'className' => 'NITSAN\\NsT3AF\\Mcp\\Tool\\Pages\\PagesListTool',
                'ownerExtensionKey' => 'ns_custom',
            ],
            [],
        );

        self::assertSame('ns_custom', $owner);
    }

    #[Test]
    public function resolveInfersOwnerFromNamespace(): void
    {
        $owner = $this->resolver->resolve(
            [
                'name' => 't3cs_list_datasources',
                'className' => 'NITSAN\\NsT3Cs\\Mcp\\Tool\\ListDatasourcesTool',
                'ownerExtensionKey' => null,
            ],
            [],
        );

        self::assertSame('ns_t3cs', $owner);
    }

    #[Test]
    public function resolveMapsFoundationToolsToCoreCard(): void
    {
        $owner = $this->resolver->resolve(
            [
                'name' => 'pages_list',
                'className' => 'NITSAN\\NsT3AF\\Mcp\\Tool\\Pages\\PagesListTool',
                'ownerExtensionKey' => null,
            ],
            [],
        );

        self::assertNull($owner);
    }

    #[Test]
    public function resolveUsesCatalogToolPrefixWhenNamespaceDoesNotMatch(): void
    {
        $owner = $this->resolver->resolve(
            [
                'name' => 't3as_search',
                'className' => 'Vendor\\Unknown\\SearchTool',
                'ownerExtensionKey' => null,
            ],
            [
                'ns_t3as' => [
                    'extensionKey' => 'ns_t3as',
                    'toolPrefix' => 't3as_',
                ],
            ],
        );

        self::assertSame('ns_t3as', $owner);
    }

    #[Test]
    public function resolveUsesExplicitToolsListFromCatalog(): void
    {
        $owner = $this->resolver->resolve(
            [
                'name' => 'special_tool',
                'className' => 'Vendor\\Unknown\\SpecialTool',
                'ownerExtensionKey' => null,
            ],
            [
                'ns_demo' => [
                    'extensionKey' => 'ns_demo',
                    'tools' => ['special_tool', 'other_tool'],
                ],
            ],
        );

        self::assertSame('ns_demo', $owner);
    }

    #[Test]
    public function inferFromClassNameHandlesT3PlanetNamespacePattern(): void
    {
        self::assertSame('ns_t3ai', $this->resolver->inferFromClassName('NITSAN\\NsT3Ai\\Mcp\\Tool\\Seo\\GenerateAllSeoTool'));
        self::assertSame('ns_t3aa', $this->resolver->inferFromClassName('NITSAN\\NsT3AA\\Mcp\\Tool\\ExampleTool'));
        self::assertSame('ns_t3af', $this->resolver->inferFromClassName('NITSAN\\NsT3AF\\Mcp\\Tool\\Pages\\PagesListTool'));
        self::assertNull($this->resolver->inferFromClassName('Vendor\\Package\\Tool'));
    }
}
