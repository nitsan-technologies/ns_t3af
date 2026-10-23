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
use NITSAN\NsT3AF\Mcp\Http\FileUploadEndpoint;
use NITSAN\NsT3AF\Mcp\Middleware\McpServerMiddleware;
use NITSAN\NsT3AF\Mcp\OAuth\AuthorizationService;
use NITSAN\NsT3AF\Mcp\Server\McpServerFactory;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpRuntimeContext;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

final class McpServerMiddlewareTest extends TestCase
{
    public function testCreateTransportUsesConfiguredMaxBodyBytes(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('maxBodyBytes')->willReturn(33554432);

        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $pathProvider = $this->createMock(McpPathProvider::class);
        $pathProvider->method('getBasePath')->willReturn('/mcp');
        $pathProvider->method('getUploadPath')->willReturn('/mcp_upload');

        $middleware = new McpServerMiddleware(
            $this->createMock(AuthorizationService::class),
            $this->createMock(BackendUserBootstrap::class),
            $this->createMock(McpServerFactory::class),
            $pathProvider,
            $settings,
            new McpRuntimeContext(),
            $this->createMock(WorkspacePreferenceService::class),
            $responseFactory,
            $streamFactory,
            $siteFinder,
            $this->createMock(FileUploadEndpoint::class),
        );

        $request = (new ServerRequest('https://example.com/mcp', 'POST'));

        $reflection = new \ReflectionClass(McpServerMiddleware::class);
        $method = $reflection->getMethod('createTransport');
        /** @var StreamableHttpTransport $transport */
        $transport = $method->invoke($middleware, $request);

        $maxBodyBytesProperty = new \ReflectionProperty(StreamableHttpTransport::class, 'maxBodyBytes');
        self::assertSame(33554432, $maxBodyBytesProperty->getValue($transport));
    }

    public function testCreateTransportLeavesProtocolVersionCheckToTheSdk(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('maxBodyBytes')->willReturn(33554432);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $pathProvider = $this->createMock(McpPathProvider::class);
        $pathProvider->method('getBasePath')->willReturn('/mcp');
        $pathProvider->method('getUploadPath')->willReturn('/mcp_upload');

        $middleware = new McpServerMiddleware(
            $this->createMock(AuthorizationService::class),
            $this->createMock(BackendUserBootstrap::class),
            $this->createMock(McpServerFactory::class),
            $pathProvider,
            $settings,
            new McpRuntimeContext(),
            $this->createMock(WorkspacePreferenceService::class),
            new ResponseFactory(),
            new StreamFactory(),
            $siteFinder,
            $this->createMock(FileUploadEndpoint::class),
        );

        $method = new \ReflectionMethod(McpServerMiddleware::class, 'createTransport');
        /** @var StreamableHttpTransport $transport */
        $transport = $method->invoke($middleware, new ServerRequest('https://example.com/mcp', 'POST'));

        $stack = (new \ReflectionProperty(StreamableHttpTransport::class, 'middleware'))->getValue($transport);
        self::assertIsArray($stack);
        self::assertSame(
            [
                \Mcp\Server\Transport\Http\Middleware\CorsMiddleware::class,
                \Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware::class,
            ],
            array_map(static fn(object $entry): string => $entry::class, $stack),
        );
    }

    public function testProcessReturnsMissingAuthenticationTokenWhenNoTokenProvided(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('isMcpServerEnabled')->willReturn(true);
        $settings->method('allowAnonymousReadOnly')->willReturn(false);

        $pathProvider = $this->createMock(McpPathProvider::class);
        $pathProvider->method('getBasePath')->willReturn('/mcp');
        $pathProvider->method('getUploadPath')->willReturn('/mcp_upload');
        $pathProvider->method('getResourceMetadataPath')->willReturn('/.well-known/oauth-protected-resource/mcp');

        $middleware = new McpServerMiddleware(
            $this->createMock(AuthorizationService::class),
            $this->createMock(BackendUserBootstrap::class),
            $this->createMock(McpServerFactory::class),
            $pathProvider,
            $settings,
            new McpRuntimeContext(),
            $this->createMock(WorkspacePreferenceService::class),
            new ResponseFactory(),
            new StreamFactory(),
            $this->createMock(SiteFinder::class),
            $this->createMock(FileUploadEndpoint::class),
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $middleware->process(
            new ServerRequest('https://example.com/mcp', 'POST'),
            $handler,
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Missing authentication token'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testOptionsPreflightAdvertisesMcpHeaders(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('isMcpServerEnabled')->willReturn(true);

        $pathProvider = $this->createMock(McpPathProvider::class);
        $pathProvider->method('getBasePath')->willReturn('/mcp');
        $pathProvider->method('getUploadPath')->willReturn('/mcp_upload');

        $middleware = new McpServerMiddleware(
            $this->createMock(AuthorizationService::class),
            $this->createMock(BackendUserBootstrap::class),
            $this->createMock(McpServerFactory::class),
            $pathProvider,
            $settings,
            new McpRuntimeContext(),
            $this->createMock(WorkspacePreferenceService::class),
            new ResponseFactory(),
            new StreamFactory(),
            $this->createMock(SiteFinder::class),
            $this->createMock(FileUploadEndpoint::class),
        );

        $request = (new ServerRequest('https://example.com/mcp', 'OPTIONS'))
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'authorization, mcp-method, mcp-name, mcp-param-foo');

        $response = $middleware->process($request, $this->createMock(RequestHandlerInterface::class));

        self::assertSame(204, $response->getStatusCode());
        $allowed = $response->getHeaderLine('Access-Control-Allow-Headers');
        self::assertStringContainsString('Mcp-Method', $allowed);
        self::assertStringContainsString('Mcp-Name', $allowed);
        self::assertStringContainsString('mcp-param-foo', $allowed);
        self::assertStringContainsString('WWW-Authenticate', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }
}
