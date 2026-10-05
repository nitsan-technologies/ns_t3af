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
 * Opening a conversation must not block on embedMany (Waiting TTFB of minutes).
 */
final class AgentConversationOpenPathTest extends TestCase
{
    private string $extRoot;

    protected function setUp(): void
    {
        $this->extRoot = dirname(__DIR__, 3);
    }

    public function testConversationActionDoesNotWarmToolIndex(): void
    {
        $source = (string) file_get_contents(
            $this->extRoot . '/Classes/Agent/Controller/AgentAjaxController.php',
        );

        $start = strpos($source, 'function conversationAction(');
        self::assertNotFalse($start);
        $end = strpos($source, 'function indexWarmAction(', $start);
        self::assertNotFalse($end, 'indexWarmAction must follow conversationAction');
        $conversationBody = substr($source, $start, $end - $start);

        self::assertStringNotContainsString('ensureFresh', $conversationBody);
        self::assertStringNotContainsString('agentToolIndex', $conversationBody);
    }

    public function testIndexWarmRouteAndActionExist(): void
    {
        $controller = (string) file_get_contents(
            $this->extRoot . '/Classes/Agent/Controller/AgentAjaxController.php',
        );
        $routes = (string) file_get_contents(
            $this->extRoot . '/Configuration/Backend/AjaxRoutes.php',
        );
        $sessionsJs = (string) file_get_contents(
            $this->extRoot . '/Resources/Public/JavaScript/agent/controller-sessions.js',
        );

        self::assertStringContainsString('function indexWarmAction(', $controller);
        self::assertStringContainsString('$this->agentToolIndex->ensureFresh()', $controller);
        self::assertStringContainsString('nst3af_agent_index_warm', $routes);
        self::assertStringContainsString('/nst3af/agent/index-warm', $routes);
        self::assertStringContainsString('nst3af_agent_index_warm', $sessionsJs);
        self::assertStringContainsString('warmToolIndexInBackground', $sessionsJs);
    }
}
