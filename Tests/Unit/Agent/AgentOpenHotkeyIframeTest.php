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

use PHPUnit\Framework\TestCase;

/**
 * Open hotkey must work in the module iframe on TYPO3 12–14 (Live Search pattern).
 */
final class AgentOpenHotkeyIframeTest extends TestCase
{
    private string $agentJs;

    protected function setUp(): void
    {
        $this->agentJs = (string) file_get_contents(dirname(__DIR__, 3) . '/Resources/Public/JavaScript/agent.js');
    }

    public function testBindsKeydownOnSameOriginIframeDocuments(): void
    {
        self::assertStringContainsString('bindOpenHotkeyOnDocument', $this->agentJs);
        self::assertStringContainsString('iframe.contentDocument', $this->agentJs);
        self::assertStringContainsString('typo3-iframe-loaded', $this->agentJs);
    }

    public function testOpenHotkeyDoesNotRequireV13HotkeysModule(): void
    {
        self::assertStringNotContainsString("import('@typo3/backend/hotkeys.js')", $this->agentJs);
    }

    public function testOpenHotkeyStopsLiveSearchOnShiftKInCapturePhase(): void
    {
        self::assertStringContainsString("addEventListener('keydown', handleOpenHotkey, true)", $this->agentJs);
        self::assertStringContainsString('stopImmediatePropagation', $this->agentJs);
    }
}
