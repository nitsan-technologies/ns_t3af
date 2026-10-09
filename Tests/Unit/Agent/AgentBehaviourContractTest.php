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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolSeverityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests mapping verify.cjs safety properties to executable PHP checks.
 */
final class AgentBehaviourContractTest extends TestCase
{
    private McpToolSeverityResolver $severityResolver;

    protected function setUp(): void
    {
        $this->severityResolver = new McpToolSeverityResolver();
    }

    public function testUnclassifiedToolHasNoSeverity(): void
    {
        self::assertNull($this->severityResolver->resolveForToolName('unknown_tool_xyz'));
    }

    public function testDynamicListToolIsRead(): void
    {
        self::assertSame(
            ToolSeverity::Read,
            $this->severityResolver->resolveForToolName('tx_news_list'),
        );
    }

    public function testDynamicDeleteToolIsDestructive(): void
    {
        self::assertSame(
            ToolSeverity::Destructive,
            $this->severityResolver->resolveForToolName('tx_news_delete'),
        );
    }

    public function testSeverityLabelsAreNeverEmptyForKnownValues(): void
    {
        foreach (ToolSeverity::cases() as $case) {
            self::assertNotSame('', $case->label());
        }
    }

    public function testDestructiveIsDistinctFromWrite(): void
    {
        self::assertNotSame(ToolSeverity::Write, ToolSeverity::Destructive);
    }
}
