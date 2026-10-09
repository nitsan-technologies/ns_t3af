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

use NITSAN\NsT3AF\Agent\Service\PermittedActionProvider;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
final class PermittedActionProviderHiddenToolsTest extends TestCase
{
    #[Test]
    public function hidesWriteDualModeWithoutPreviewable(): void
    {
        self::assertTrue($this->provider()->isHiddenFromAgent([
            'name' => 't3ai_create_page_simple',
            'dualMode' => true,
            'previewable' => false,
            'severity' => ToolSeverity::Write->value,
        ]));
    }

    #[Test]
    public function keepsReadDualModeVisible(): void
    {
        self::assertFalse($this->provider()->isHiddenFromAgent([
            'name' => 't3aa_summarize_content',
            'dualMode' => true,
            'previewable' => false,
            'severity' => ToolSeverity::Read->value,
        ]));
    }

    #[Test]
    public function keepsPreviewableWriteVisible(): void
    {
        self::assertFalse($this->provider()->isHiddenFromAgent([
            'name' => 't3ai_generate_all_seo',
            'dualMode' => true,
            'previewable' => true,
            'severity' => ToolSeverity::Write->value,
        ]));
    }

    #[Test]
    public function hidesPerFieldSeoDenylist(): void
    {
        self::assertTrue($this->provider()->isHiddenFromAgent([
            'name' => 't3ai_generate_meta_description',
            'dualMode' => true,
            'previewable' => false,
            'severity' => ToolSeverity::Write->value,
        ]));
    }

    private function provider(): PermittedActionProvider
    {
        // isHiddenFromAgent only reads the tool array + private constants.
        return (new ReflectionClass(PermittedActionProvider::class))->newInstanceWithoutConstructor();
    }
}
