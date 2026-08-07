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
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Builds absolute backend return URLs for Pabbly checkout (cf_redirectto_*).
 *
 * External redirects must include scheme + host. Backend route tokens are stripped
 * because the post-checkout landing page relies on the active backend session.
 *
 * @internal
 */
final class CreditsReturnUrlBuilder
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly CreditsDomainResolver $domainResolver,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     */
    public function fromRoute(string $routeName, array $parameters = []): string
    {
        return $this->stripBackendRouteToken(
            (string) $this->uriBuilder->buildUriFromRoute(
                $routeName,
                $parameters,
                UriBuilder::ABSOLUTE_URL,
            ),
        );
    }

    public function normalize(string $returnUrl): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '') {
            return '';
        }

        if ($this->isAbsoluteUrl($returnUrl)) {
            return $this->stripBackendRouteToken($returnUrl);
        }

        $path = str_starts_with($returnUrl, '/') ? $returnUrl : '/' . $returnUrl;

        return $this->stripBackendRouteToken($this->absolutePath($path));
    }

    private function absolutePath(string $path): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $uri = $request->getUri();
            $host = $uri->getHost();
            if ($host !== '') {
                $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';

                return $this->composeUrl($scheme, $host, $uri->getPort(), $path, '');
            }
        }

        $host = $this->domainResolver->resolve();

        return $this->composeUrl('https', $host, null, $path, '');
    }

    private function stripBackendRouteToken(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $queryString = (string) ($parts['query'] ?? '');
        if ($queryString === '') {
            return $url;
        }

        parse_str($queryString, $query);
        if (!array_key_exists('token', $query)) {
            return $url;
        }

        unset($query['token']);
        $parts['query'] = $query === [] ? null : http_build_query($query);

        return $this->composeUrlFromParts($parts);
    }

    private function isAbsoluteUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function composeUrl(string $scheme, string $host, ?int $port, string $path, string $query): string
    {
        $portSuffix = $port !== null && !in_array($port, [80, 443], true) ? ':' . $port : '';
        $querySuffix = $query !== '' ? '?' . $query : '';

        return $scheme . '://' . $host . $portSuffix . $path . $querySuffix;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function composeUrlFromParts(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = (string) ($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== ''
            ? '?' . $parts['query']
            : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }
}
