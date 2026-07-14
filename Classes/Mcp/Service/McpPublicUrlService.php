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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the public MCP origin (scheme + host + port).
 *
 * MCP middleware is registered on the frontend stack at {@see McpPathProvider::getBasePath()}
 * (default {@code /mcp}) — independent of the TYPO3 site base path ({@code /}, {@code /ai}, …).
 */
readonly class McpPublicUrlService
{
    public function __construct(private SiteFinder $siteFinder) {}

    public function resolveOrigin(?ServerRequestInterface $request = null): string
    {
        return $this->originFromRequest($request)
            ?? $this->originFromReverseProxyConfig()
            ?? $this->originFromSites()
            ?? $this->originFromEnvironment()
            ?? 'https://your-site.com';
    }

    public function buildServerUrl(McpPathProvider $pathProvider, ?ServerRequestInterface $request = null): string
    {
        return rtrim($this->resolveOrigin($request), '/') . $pathProvider->getBasePath();
    }

    private function originFromRequest(?ServerRequestInterface $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            $hostHeader = $request->getHeaderLine('Host');
            if ($hostHeader !== '') {
                $host = (string) (parse_url('http://' . $hostHeader, PHP_URL_HOST) ?? '');
            }
        }

        if ($host === '') {
            return null;
        }

        $scheme = $uri->getScheme();
        if ($scheme === '') {
            $scheme = str_contains($request->getHeaderLine('X-Forwarded-Proto'), 'https') ? 'https' : 'http';
        }

        return $this->formatOrigin($scheme, $host, $uri->getPort());
    }

    private function originFromReverseProxyConfig(): ?string
    {
        $baseUrl = $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] ?? '';
        if (!is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        return $this->parseAbsoluteOrigin($baseUrl);
    }

    private function originFromSites(): ?string
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $base = $site->getBase();
                $host = $base->getHost();
                if ($host === '') {
                    continue;
                }

                return $this->formatOrigin($base->getScheme() ?: 'https', $host, $base->getPort());
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function originFromEnvironment(): ?string
    {
        $envHost = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        if (!is_string($envHost) || $envHost === '') {
            return null;
        }

        return $this->parseAbsoluteOrigin($envHost);
    }

    private function parseAbsoluteOrigin(string $url): ?string
    {
        if (!str_contains($url, '://')) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = is_string($parts['scheme'] ?? null) && $parts['scheme'] !== '' ? $parts['scheme'] : 'https';
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        return $this->formatOrigin($scheme, (string) $parts['host'], $port);
    }

    private function formatOrigin(string $scheme, string $host, ?int $port): string
    {
        $origin = ($scheme !== '' ? $scheme : 'https') . '://' . $host;
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }
}
