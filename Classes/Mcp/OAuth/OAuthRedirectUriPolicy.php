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

namespace NITSAN\NsT3AF\Mcp\OAuth;

use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;

/**
 * Enforces Extension Settings "oauthDefaultRedirectUris" when configured (one URI per line).
 * Lines ending with "*" match parsed scheme/host/path (never raw string prefix on the full URI).
 */
final readonly class OAuthRedirectUriPolicy
{
    public function __construct(
        private AdvancedSettingsService $advancedSettingsService,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedRedirectUris(): array
    {
        $raw = trim($this->advancedSettingsService->oauthDefaultRedirectUris());
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $line): string => trim($line),
            preg_split('/\R/', $raw) ?: [],
        ), static fn(string $line): bool => $line !== ''));
    }

    public function isEnforced(): bool
    {
        return $this->allowedRedirectUris() !== [];
    }

    public function isAllowed(string $redirectUri): bool
    {
        $parsed = $this->parseRedirectUri($redirectUri);
        if ($parsed === null) {
            return false;
        }

        $allowed = $this->allowedRedirectUris();
        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $entry) {
            foreach ($this->expandAllowListEntry($entry) as $matchEntry) {
                if ($this->matchesAllowListEntry($parsed, $redirectUri, $matchEntry)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $redirectUris
     */
    public function assertAllAllowed(array $redirectUris): void
    {
        foreach ($redirectUris as $uri) {
            if (!$this->isAllowed($uri)) {
                throw new \InvalidArgumentException(
                    'Redirect URI is not on the allowed list configured in MCP OAuth settings.',
                    1747340001,
                );
            }
        }
    }

    public static function redirectHost(string $redirectUri): string
    {
        $parts = parse_url($redirectUri);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        return isset($parts['host']) && is_string($parts['host'])
            ? $parts['host']
            : $redirectUri;
    }

    /**
     * Shorthand allow-list lines expand to the redirect URIs MCP clients actually register today.
     *
     * @return list<string>
     */
    private function expandAllowListEntry(string $entry): array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return [];
        }

        if (strcasecmp($entry, 'cursor://*') === 0 || strcasecmp($entry, 'cursor:*') === 0) {
            return [
                'cursor://*',
                'https://www.cursor.com/agents/mcp/oauth/*',
                'http://localhost:*',
                'http://127.0.0.1:*',
            ];
        }

        return [$entry];
    }

    /**
     * @param array{scheme: string, host: string, port: ?int, path: string} $parsed
     */
    private function matchesAllowListEntry(array $parsed, string $rawRedirectUri, string $entry): bool
    {
        if (str_ends_with($entry, '*')) {
            $prefix = substr($entry, 0, -1);

            return $this->matchesWildcardPrefix($parsed, $prefix);
        }

        if ($rawRedirectUri !== $entry) {
            return false;
        }

        $expected = $this->parseRedirectUri($entry);

        return $expected !== null && $this->redirectPartsEqual($parsed, $expected);
    }

    /**
     * @param array{scheme: string, host: string, port: ?int, path: string} $parsed
     */
    private function matchesWildcardPrefix(array $parsed, string $prefix): bool
    {
        if ($prefix === 'cursor://') {
            return $parsed['scheme'] === 'cursor';
        }

        if (preg_match('#^http://(localhost|127\\.0\\.0\\.1):?$#i', $prefix, $match) === 1) {
            return $parsed['scheme'] === 'http'
                && $this->isLoopbackHttpHost($parsed['host'], strtolower($match[1]));
        }

        if (str_starts_with(strtolower($prefix), 'https://')) {
            $patternParts = parse_url($prefix);
            if (!is_array($patternParts) || !isset($patternParts['host']) || !is_string($patternParts['host'])) {
                return false;
            }

            $pathPrefix = is_string($patternParts['path'] ?? null) && $patternParts['path'] !== ''
                ? $patternParts['path']
                : '/';

            return $parsed['scheme'] === 'https'
                && $parsed['host'] === strtolower($patternParts['host'])
                && str_starts_with($parsed['path'], $pathPrefix);
        }

        return false;
    }

    /**
     * @return array{scheme: string, host: string, port: ?int, path: string}|null
     */
    private function parseRedirectUri(string $redirectUri): ?array
    {
        $parts = parse_url($redirectUri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !is_string($parts['scheme']) || !is_string($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            return null;
        }

        return [
            'scheme' => strtolower($parts['scheme']),
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => is_string($parts['path'] ?? null) ? $parts['path'] : '',
        ];
    }

    /**
     * @param array{scheme: string, host: string, port: ?int, path: string} $a
     * @param array{scheme: string, host: string, port: ?int, path: string} $b
     */
    private function redirectPartsEqual(array $a, array $b): bool
    {
        return $a['scheme'] === $b['scheme']
            && $a['host'] === $b['host']
            && $a['port'] === $b['port']
            && $a['path'] === $b['path'];
    }

    private function isLoopbackHttpHost(string $uriHost, string $patternHost): bool
    {
        if ($uriHost === $patternHost) {
            return true;
        }

        if ($patternHost !== 'localhost' && $patternHost !== '127.0.0.1') {
            return false;
        }

        return in_array($uriHost, ['::1', '[::1]'], true);
    }
}
