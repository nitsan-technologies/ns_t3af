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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\Backend;

use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpToolMetadataServiceTest extends TestCase
{
    #[Test]
    public function getForToolReturnsMetadataForCoreTool(): void
    {
        $service = new McpToolMetadataService();
        $metadata = $service->getForTool('pages_get');

        self::assertSame('Content', $metadata['category']);
        self::assertSame('ready', $metadata['status']);
        self::assertNotSame('', $metadata['tagline']);
        self::assertCount(4, $metadata['examplePrompts']);
    }

    #[Test]
    public function getForToolMergesDefaultsForUnknownTool(): void
    {
        $service = new McpToolMetadataService();
        $metadata = $service->getForTool('unknown_tool_xyz');

        self::assertSame('Records', $metadata['category']);
        self::assertSame('ready', $metadata['status']);
        self::assertSame('', $metadata['tagline']);
        self::assertSame([], $metadata['examplePrompts']);
    }

    #[Test]
    public function getCategoriesReturnsConfiguredCategories(): void
    {
        $service = new McpToolMetadataService();
        $categories = $service->getCategories();

        self::assertNotEmpty($categories);
        self::assertSame('Content', $categories[0]['key']);
        self::assertNotSame('', $categories[0]['label']);
    }
}
