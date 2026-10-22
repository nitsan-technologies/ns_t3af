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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Controller;

use NITSAN\NsT3AF\Mcp\Controller\Backend\McpServerController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The MCP server settings are installation-wide: a backend login alone must not change them.
 *
 * @internal
 */
final class McpServerControllerAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function noBackendUserIsDenied(): void
    {
        unset($GLOBALS['BE_USER']);

        $response = $this->controller()->saveMcpModeAction($this->createMock(ServerRequestInterface::class));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function anAdminGetsPastTheGuard(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $user;
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['mcpMode' => 'qa-invalid']);

        $response = $this->controller()->saveMcpModeAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    private function controller(): McpServerController
    {
        return (new ReflectionClass(McpServerController::class))->newInstanceWithoutConstructor();
    }
}
