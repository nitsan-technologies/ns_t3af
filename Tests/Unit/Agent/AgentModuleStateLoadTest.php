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
 * ModuleStateStorage must load lazily so a failed core asset does not blank the panel.
 */
final class AgentModuleStateLoadTest extends TestCase
{
    private string $extRoot;

    protected function setUp(): void
    {
        $this->extRoot = dirname(__DIR__, 3);
    }

    public function testAgentBundleDoesNotStaticallyImportModuleStateStorage(): void
    {
        $agentJs = (string) file_get_contents($this->extRoot . '/Resources/Public/JavaScript/agent.js');
        $contextJs = (string) file_get_contents($this->extRoot . '/Resources/Public/JavaScript/agent/context.js');

        self::assertStringNotContainsString(
            '@typo3/backend/storage/module-state-storage.js',
            $agentJs,
        );
        self::assertStringNotContainsString(
            '@typo3/backend/storage/module-state-storage.js',
            $contextJs,
        );
        self::assertStringContainsString('preloadModuleStateStorage', $agentJs);
        self::assertStringContainsString('currentWebPageId', $contextJs);
    }

    public function testToolbarItemDoesNotBootAgentJsAgain(): void
    {
        $source = (string) file_get_contents(
            $this->extRoot . '/Classes/Agent/Backend/ToolbarItems/AgentToolbarItem.php',
        );

        self::assertStringNotContainsString('JavaScriptModuleInstruction', $source);
        self::assertStringNotContainsString('addJavaScriptModuleInstruction', $source);
    }
}
