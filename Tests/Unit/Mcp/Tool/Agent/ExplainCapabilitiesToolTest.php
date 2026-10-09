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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Agent;

use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Mcp\Tool\Agent\ExplainCapabilitiesTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ExplainCapabilitiesToolTest extends TestCase
{
    #[Test]
    public function executeListsPagePreferredToolsFirstWithShortLabels(): void
    {
        $executable = [
            [
                'name' => 't3ai_generate_all_seo',
                'editorLabel' => 'Generate and apply all SEO metadata fields to a TYPO3 page using the configured AI provider',
                'severity' => 'write',
            ],
            [
                'name' => 'cache_clear',
                'editorLabel' => 'Clear caches',
                'severity' => 'destructive',
            ],
            [
                'name' => 'content_list',
                'editorLabel' => 'List page content',
                'severity' => 'read',
            ],
            [
                'name' => 'pages_get',
                'editorLabel' => 'Get page properties',
                'severity' => 'read',
            ],
            [
                'name' => 'backend_user_list',
                'editorLabel' => 'List backend users',
                'severity' => 'read',
            ],
        ];

        $catalog = $this->createMock(AgentActionCatalogInterface::class);
        $catalog->method('buildCatalog')->willReturn([
            'executable' => $executable,
            'locked' => [],
        ]);

        $tool = new ExplainCapabilitiesTool($catalog);
        $payload = json_decode($tool->execute('web_layout', 49), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertSame(['content_list', 'pages_get', 't3ai_generate_all_seo'], $payload['listedTools']);
        self::assertStringContainsString('List page content', $payload['summary']);
        self::assertStringNotContainsString('using the configured AI provider', $payload['summary']);
        self::assertStringNotContainsString('Clear caches', $payload['summary']);
        self::assertStringNotContainsString('backend users', $payload['summary']);
    }
}
