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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Domain\Repository\TokenRepository;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class McpServerStatusService
{
    public function __construct(
        private AdvancedSettingsService $settingsService,
        private TokenRepository $tokenRepository,
        private McpPathProvider $pathProvider,
        private McpPublicUrlService $publicUrlService,
        private RequestFactory $requestFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?ServerRequestInterface $request = null): array
    {
        $enabled = $this->settingsService->isMcpServerEnabled();
        $basePath = $this->pathProvider->getBasePath();
        $root = rtrim($this->publicUrlService->resolveOrigin($request), '/');
        $serverUrl = $root . $basePath;
        $authorizationServerPath = $this->pathProvider->getMetadataPath();
        $protectedResourcePath = $this->pathProvider->getResourceMetadataPath();
        $authorizationServerUrl = $root . $authorizationServerPath;
        $protectedResourceUrl = $root . $protectedResourcePath;

        return [
            'online' => $enabled ? 1 : 0,
            'activeClients' => $this->tokenRepository->countActiveGlobal(),
            'oauthEndpoints' => [
                'authorizationServer' => $authorizationServerPath,
                'protectedResource' => $protectedResourcePath,
            ],
            'oauthEndpointUrls' => [
                'authorizationServer' => $authorizationServerUrl,
                'protectedResource' => $protectedResourceUrl,
            ],
            'endpointChecks' => [
                'mcp' => $enabled && $this->isMcpEndpointOnline($serverUrl) ? 1 : 0,
                'authorizationServer' => $enabled && $this->isReachable($authorizationServerUrl) ? 1 : 0,
                'protectedResource' => $enabled && $this->isReachable($protectedResourceUrl) ? 1 : 0,
            ],
            'serverUrl' => $serverUrl,
            'mcpBasePath' => $basePath,
        ];
    }

    private function isMcpEndpointOnline(string $url): bool
    {
        $statusCode = $this->fetchStatusCode($url);
        if ($statusCode === null) {
            return false;
        }

        // Unauthenticated GET to /mcp correctly returns 401 + WWW-Authenticate when auth is required.
        return ($statusCode >= 200 && $statusCode < 400) || $statusCode === 401;
    }

    private function isReachable(string $url): bool
    {
        $statusCode = $this->fetchStatusCode($url);

        return $statusCode !== null && $statusCode >= 200 && $statusCode < 400;
    }

    private function fetchStatusCode(string $url): ?int
    {
        if ($url === '' || !str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => 3,
                'allow_redirects' => true,
                // Guzzle throws ClientException on 4xx by default; we need the status code.
                'http_errors' => false,
            ]);

            return $response->getStatusCode();
        } catch (ClientExceptionInterface $exception) {
            return $this->extractStatusCodeFromException($exception);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractStatusCodeFromException(ClientExceptionInterface $exception): ?int
    {
        if (!method_exists($exception, 'getResponse')) {
            return null;
        }

        $response = $exception->getResponse();
        if (!$response instanceof ResponseInterface) {
            return null;
        }

        return $response->getStatusCode();
    }
}
