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

use NITSAN\NsT3AF\Mcp\Service\McpToolsRegistryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpToolsRegistryServiceTest extends TestCase
{
    #[Test]
    public function buildDynamicToolNamesReturnsNineCrudToolsForPrefix(): void
    {
        self::assertSame(
            [
                'news_list',
                'news_get',
                'news_create',
                'news_update',
                'news_delete',
                'news_move',
                'news_delete_batch',
                'news_update_batch',
                'news_move_batch',
            ],
            McpToolsRegistryService::buildDynamicToolNames('news'),
        );
    }

    #[Test]
    public function toolsPerDynamicTableMatchesGeneratedToolCount(): void
    {
        self::assertSame(
            McpToolsRegistryService::TOOLS_PER_DYNAMIC_TABLE,
            count(McpToolsRegistryService::buildDynamicToolNames('blog_post')),
        );
    }
}
