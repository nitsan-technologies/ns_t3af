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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Middleware;

use Mcp\Server\Transport\StreamableHttpTransport;
use NITSAN\NsT3AF\Mcp\Authentication\BackendUserBootstrap;
use NITSAN\NsT3AF\Mcp\Middleware\McpServerMiddleware;
use NITSAN\NsT3AF\Mcp\OAuth\AuthorizationService;
use NITSAN\NsT3AF\Mcp\Server\McpServerFactory;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpRuntimeContext;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

final class McpServerMiddlewareTest extends TestCase
{
    public function testCreateTransportUsesConfiguredMaxBodyBytes(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('maxBodyBytes')->willReturn(33554432);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $middleware = new McpServerMiddleware(
            $this->createMock(AuthorizationService::class),
            $this->createMock(BackendUserBootstrap::class),
            $this->createMock(McpServerFactory::class),
            $this->createMock(McpPathProvider::class),
            $settings,
            new McpRuntimeContext(),
            $this->createMock(WorkspacePreferenceService::class),
            $responseFactory,
            $streamFactory,
            $siteFinder,
        );

        $request = (new ServerRequest('https://example.com/mcp', 'POST'));

        $reflection = new \ReflectionClass(McpServerMiddleware::class);
        $method = $reflection->getMethod('createTransport');
        /** @var StreamableHttpTransport $transport */
        $transport = $method->invoke($middleware, $request);

        $maxBodyBytesProperty = new \ReflectionProperty(StreamableHttpTransport::class, 'maxBodyBytes');
        self::assertSame(33554432, $maxBodyBytesProperty->getValue($transport));
    }
}
