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

namespace NITSAN\NsT3AF\Credits\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the hostname sent as {@code domain} on every T3Planet Credits API call.
 *
 * Backend HTTP requests use the live request host. CLI and scheduler contexts have no
 * {@code HTTP_HOST}; they reuse the domain stored at token activation and fall back to
 * TYPO3 site configuration, reverse-proxy settings, and container environment.
 *
 * @internal
 */
final class CreditsDomainResolver
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly RuntimeSettingsService $runtimeSettings,
    ) {}

    public function resolve(?ServerRequestInterface $request = null): string
    {
        $host = $this->resolveHost($request);
        $normalized = $this->normalizeHost($host);
        $this->maybePersistResolvedDomain($host, $normalized);

        return $normalized;
    }

    private function resolveHost(?ServerRequestInterface $request): string
    {
        if ($request !== null) {
            $host = $request->getUri()->getHost();
            if ($host !== '') {
                return $host;
            }
        }

        $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if (is_string($serverHost) && trim($serverHost) !== '') {
            return $serverHost;
        }

        $stored = $this->runtimeSettings->getCreditsDomain();
        if ($stored !== '') {
            return $stored;
        }

        $fromProxy = $this->hostFromConfiguredUrl(
            (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] ?? ''),
        );
        if ($fromProxy !== '') {
            return $fromProxy;
        }

        $fromSite = $this->hostFromSites();
        if ($fromSite !== '') {
            return $fromSite;
        }

        $ddevPrimary = getenv('DDEV_PRIMARY_URL');
        if (is_string($ddevPrimary) && $ddevPrimary !== '') {
            $fromDdev = $this->hostFromConfiguredUrl($ddevPrimary);
            if ($fromDdev !== '') {
                return $fromDdev;
            }
        }

        $requestHost = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        $fromEnv = $this->hostFromConfiguredUrl(is_string($requestHost) ? $requestHost : '');
        if ($fromEnv !== '' && $fromEnv !== 'localhost') {
            return $fromEnv;
        }

        $fromExtension = $this->runtimeSettings->getCreditsDomainOverride();
        if ($fromExtension !== '') {
            return $fromExtension;
        }

        return '';
    }

    private function hostFromSites(): string
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $host = $site->getBase()->getHost();
                if ($host !== '') {
                    return $host;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function hostFromConfiguredUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !str_contains($url, '://')) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return (string) $parts['host'];
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return 'localhost';
        }

        if (str_contains($host, ':')) {
            $host = GeneralUtility::trimExplode(':', $host, true)[0] ?? $host;
        }

        return $host;
    }

    private function maybePersistResolvedDomain(string $rawHost, string $normalized): void
    {
        if ($rawHost === '' || $normalized === '' || $normalized === 'localhost') {
            return;
        }

        if ($this->runtimeSettings->getCreditsDomain() !== '') {
            return;
        }

        $this->runtimeSettings->storeCreditsDomain($normalized);
    }
}
