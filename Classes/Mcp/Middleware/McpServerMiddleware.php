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

use Mcp\Server\Transport\StreamableHttpTransport;
use NITSAN\NsT3AF\Mcp\Authentication\BackendUserBootstrap;
use NITSAN\NsT3AF\Mcp\OAuth\AuthorizationService;
use NITSAN\NsT3AF\Mcp\Server\McpServerFactory;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpRuntimeContext;
use NITSAN\NsT3AF\Mcp\Service\McpPathProvider;
use NITSAN\NsT3AF\Mcp\Service\WorkspacePreferenceService;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class McpServerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthorizationService $authorizationService,
        private BackendUserBootstrap $backendUserBootstrap,
        private McpServerFactory $mcpServerFactory,
        private McpPathProvider $pathProvider,
        private AdvancedSettingsService $settingsService,
        private McpRuntimeContext $runtimeContext,
        private WorkspacePreferenceService $workspacePreferenceService,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private SiteFinder $siteFinder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $basePath = $this->pathProvider->getBasePath();
        $urlToken = $this->extractUrlToken($path, $basePath);

        if ($path !== $basePath && $urlToken === null) {
            return $handler->handle($request);
        }

        if (!$this->settingsService->isMcpServerEnabled()) {
            return $this->withCorsHeaders($this->createJsonResponse(['error' => 'MCP server is disabled'], 503));
        }

        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCorsHeaders($this->responseFactory->createResponse(204));
        }

        $token = $urlToken ?? $this->extractQueryToken($request) ?? $this->extractBearerToken($request);
        if ($token === null) {
            if ($this->settingsService->allowAnonymousReadOnly()) {
                return $this->withCorsHeaders($this->createJsonResponse(['error' => 'Anonymous read-only not implemented in v1'], 501));
            }

            return $this->withCorsHeaders($this->createUnauthorizedResponse($request, 'Missing or invalid Authorization header'));
        }

        try {
            $context = $this->authorizationService->validateAccessToken($token);
            $this->runtimeContext->set(
                (int) $context['tokenUid'],
                (string) ($context['clientLabel'] ?? ''),
                (int) $context['beUser'],
            );
            $this->backendUserBootstrap->bootstrap($context['beUser'], $context['workspaceId']);
            $workspaceId = $this->workspacePreferenceService->getForUser($context['beUser']);
            if (AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
                $GLOBALS['BE_USER']->setWorkspace($workspaceId);
            }
        } catch (\RuntimeException) {
            return $this->withCorsHeaders($this->createUnauthorizedResponse($request, 'Authentication failed'));
        }

        try {
            $server = $this->mcpServerFactory->create();
            $transport = $this->createTransport($request);

            /** @var ResponseInterface $response */
            $response = $server->run($transport);

            if ($this->isSessionNotFoundResponse($response)) {
                return $this->withCorsHeaders($this->createUnauthorizedResponse($request, 'Session expired'));
            }

            return $this->withCorsHeaders($response);
        } finally {
            $this->runtimeContext->clear();
        }
    }

    /**
     * Builds the streamable HTTP transport.
     *
     * mcp/sdk v0.6+ enables a default DnsRebindingProtectionMiddleware whose
     * allow-list is localhost-only, which rejects every real site host with
     * "Forbidden: Invalid Host header.". We therefore pass an explicit middleware
     * stack that allows this instance's hosts. Older SDKs (v0.5) lack that middleware
     * and use a different constructor signature, so we keep the legacy 3-argument
     * call for them.
     */
    private function createTransport(ServerRequestInterface $request): StreamableHttpTransport
    {
        $corsMiddlewareClass = 'Mcp\\Server\\Transport\\Http\\Middleware\\CorsMiddleware';
        $dnsMiddlewareClass = 'Mcp\\Server\\Transport\\Http\\Middleware\\DnsRebindingProtectionMiddleware';
        $protocolMiddlewareClass = 'Mcp\\Server\\Transport\\Http\\Middleware\\ProtocolVersionMiddleware';

        if (
            !method_exists(StreamableHttpTransport::class, 'defaultMiddleware')
            || !class_exists($corsMiddlewareClass)
            || !class_exists($dnsMiddlewareClass)
            || !class_exists($protocolMiddlewareClass)
        ) {
            return new StreamableHttpTransport($request, $this->responseFactory, $this->streamFactory);
        }

        $middleware = [
            new $corsMiddlewareClass(),
            new $dnsMiddlewareClass($this->buildAllowedHosts($request), $this->responseFactory, $this->streamFactory),
            new $protocolMiddlewareClass(),
        ];

        return new StreamableHttpTransport(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            null,
            $middleware,
        );
    }

    /**
     * Hosts the bundled SDK's DNS-rebinding protection must accept: localhost
     * variants, the current request host (already validated by TYPO3's
     * trustedHostsPattern), and every configured site/language base host so
     * multi-domain instances keep working.
     *
     * @return list<string>
     */
    private function buildAllowedHosts(ServerRequestInterface $request): array
    {
        $hosts = ['localhost', '127.0.0.1', '[::1]'];
        $hosts[] = $request->getUri()->getHost();

        foreach ($this->siteFinder->getAllSites() as $site) {
            $hosts[] = $site->getBase()->getHost();
            foreach ($site->getAllLanguages() as $language) {
                $hosts[] = $language->getBase()->getHost();
            }
        }

        $hosts = array_filter($hosts, static fn(string $host): bool => $host !== '');

        return array_values(array_unique($hosts));
    }

    private function extractUrlToken(string $path, string $basePath): ?string
    {
        $prefix = rtrim($basePath, '/') . '/r/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $token = substr($path, strlen($prefix));

        return $token !== '' && preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $token : null;
    }

    private function extractQueryToken(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        $token = $params['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $token : null;
    }

    private function isSessionNotFoundResponse(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 404) {
            return false;
        }

        $body = (string) $response->getBody();
        $response->getBody()->rewind();

        return $body !== ''
            && str_contains($body, '"code":-32600')
            && str_contains($body, 'Session not found');
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            $serverParams = $request->getServerParams();
            if (is_string($serverParams['HTTP_AUTHORIZATION'] ?? null)) {
                $authHeader = $serverParams['HTTP_AUTHORIZATION'];
            } elseif (is_string($serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
                $authHeader = $serverParams['REDIRECT_HTTP_AUTHORIZATION'];
            }
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        return $token !== '' ? $token : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createJsonResponse(array $payload, int $status): ResponseInterface
    {
        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }

    private function createUnauthorizedResponse(ServerRequestInterface $request, string $error): ResponseInterface
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUrl .= ':' . $uri->getPort();
        }

        $resourceMetadataUrl = $baseUrl . $this->pathProvider->getResourceMetadataPath();
        $body = $this->streamFactory->createStream(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $resourceMetadataUrl))
            ->withBody($body);
    }

    private function withCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader(
                'Access-Control-Allow-Headers',
                'Accept, Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID',
            )
            ->withHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
