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
use NITSAN\NsT3AF\Mcp\Tool\Agent\AskClarificationTool;
use NITSAN\NsT3AF\Mcp\Tool\Agent\ExplainCapabilitiesTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentCapabilityToolsTest extends TestCase
{
    #[Test]
    public function explainCapabilitiesListsExecutableTools(): void
    {
        $catalog = new class implements AgentActionCatalogInterface {
            public function buildCatalog(): array
            {
                return [
                    'executable' => [
                        [
                            'name' => 'pages_get',
                            'editorLabel' => 'Inspect this page',
                            'severityLabel' => 'Read',
                        ],
                        [
                            'name' => 't3ai_generate_all_seo',
                            'editorLabel' => 'Generate SEO metadata',
                            'severityLabel' => 'Write',
                        ],
                    ],
                    'locked' => [
                        ['name' => 'locked_tool'],
                    ],
                ];
            }
        };

        $tool = new ExplainCapabilitiesTool($catalog);
        $raw = $tool->execute('web_layout', 49);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($decoded['ok'] ?? false);
        self::assertSame(2, $decoded['executableCount'] ?? 0);
        self::assertSame(1, $decoded['lockedCount'] ?? 0);
        self::assertSame(49, $decoded['pageId'] ?? 0);
        self::assertSame(['pages_get', 't3ai_generate_all_seo'], $decoded['listedTools'] ?? null);
        $summary = (string) ($decoded['summary'] ?? '');
        self::assertStringContainsString('On this page I can help with:', $summary);
        self::assertStringContainsString('Inspect this page', $summary);
        self::assertStringContainsString('Generate SEO metadata', $summary);
        self::assertStringContainsString('1 actions need another extension or plan.', $summary);
    }

    #[Test]
    public function askClarificationRequiresQuestion(): void
    {
        $tool = new AskClarificationTool();
        $empty = json_decode($tool->execute(''), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($empty['ok'] ?? true);

        $ok = json_decode($tool->execute('Which SEO fields?'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($ok['ok'] ?? false);
        self::assertSame('Which SEO fields?', $ok['summary'] ?? null);
    }
}
