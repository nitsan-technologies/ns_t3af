<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


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

use NITSAN\NsT3AF\Settings\ExtensionSettingsService;

readonly class McpPathProvider
{
    private const DEFAULT_BASE_PATH = '/mcp';

    private const EXTENSION_KEY = 'ns_t3af';

    private string $basePath;

    public function __construct(ExtensionSettingsService $extensionSettingsService)
    {
        $config = $extensionSettingsService->getAll(self::EXTENSION_KEY);
        $raw = $config['mcpBasePath'] ?? null;
        $this->basePath = $this->normalize(is_string($raw) ? $raw : self::DEFAULT_BASE_PATH);
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getAuthorizePath(): string
    {
        return $this->basePath . '/oauth/authorize';
    }

    public function getTokenPath(): string
    {
        return $this->basePath . '/oauth/token';
    }

    public function getRegisterPath(): string
    {
        return $this->basePath . '/oauth/register';
    }

    public function getRevokePath(): string
    {
        return $this->basePath . '/oauth/revoke';
    }

    public function getOAuthCookiePath(): string
    {
        return $this->basePath . '/oauth';
    }

    /**
     * RFC 8414 §3.1: the well-known URI is inserted between the host and the
     * issuer's path component, so the metadata for issuer `https://host/<path>`
     * lives at `/.well-known/oauth-authorization-server/<path>`.
     */
    public function getMetadataPath(): string
    {
        return '/.well-known/oauth-authorization-server' . $this->basePath;
    }

    /**
     * RFC 9728 §3: same path-insert convention for protected resource metadata —
     * resource `https://host/<path>` is described at
     * `/.well-known/oauth-protected-resource/<path>`.
     */
    public function getResourceMetadataPath(): string
    {
        return '/.well-known/oauth-protected-resource' . $this->basePath;
    }

    private function normalize(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return self::DEFAULT_BASE_PATH;
        }
        if (!str_starts_with($trimmed, '/')) {
            $trimmed = '/' . $trimmed;
        }
        $trimmed = rtrim($trimmed, '/');
        if (preg_match('/[\s?#]/', $trimmed) === 1) {
            return self::DEFAULT_BASE_PATH;
        }
        return $trimmed;
    }
}
