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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Middleware;

use const JSON_THROW_ON_ERROR;

use NITSAN\NsT3AF\Mcp\Service\Backend\McpSecurityService;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Enforces MCP security policies: IP allowlist and optional mTLS client verification.
 *
 * @internal
 */
readonly class McpSecurityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private McpSecurityService $securityService,
        private McpPathProvider $pathProvider,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isMcpRequest($request)) {
            return $handler->handle($request);
        }

        $clientIp = $this->resolveIpAddress($request);
        if (!$this->securityService->isIpAllowed($clientIp)) {
            return $this->createJsonResponse(403, [
                'error' => 'access_denied',
                'error_description' => 'Client IP address is not on the MCP allowlist.',
            ]);
        }

        if (!$this->securityService->validateMtls($request)) {
            return $this->createJsonResponse(403, [
                'error' => 'access_denied',
                'error_description' => 'Valid client certificate required for MCP access.',
            ]);
        }

        return $handler->handle($request);
    }

    private function isMcpRequest(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $basePath = $this->pathProvider->getBasePath();

        if ($path === $basePath || str_starts_with($path, rtrim($basePath, '/') . '/r/')) {
            return true;
        }

        foreach ([
            $this->pathProvider->getAuthorizePath(),
            $this->pathProvider->getTokenPath(),
            $this->pathProvider->getRegisterPath(),
            $this->pathProvider->getRevokePath(),
            $this->pathProvider->getMetadataPath(),
            $this->pathProvider->getResourceMetadataPath(),
        ] as $oauthPath) {
            if ($path === $oauthPath) {
                return true;
            }
        }

        return false;
    }

    private function resolveIpAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRemoteAddress() : '';
    }

    /** @param array<string, mixed> $data */
    private function createJsonResponse(int $statusCode, array $data): ResponseInterface
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }
}
