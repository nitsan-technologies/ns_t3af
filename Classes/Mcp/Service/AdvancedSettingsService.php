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

use NITSAN\NsT3AF\Settings\ExtensionSettingsService;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class AdvancedSettingsService
{
    private const EXTENSION_KEY = 'ns_t3af';

    public function __construct(private ExtensionSettingsService $extensionSettingsService) {}

    public function isMcpServerEnabled(): bool
    {
        return $this->bool('enableMcpServer', true);
    }

    public function requireAuth(): bool
    {
        return $this->bool('requireAuth', true);
    }

    public function rateLimitGlobal(): bool
    {
        return $this->bool('rateLimitGlobal', true);
    }

    public function logAllToolCalls(): bool
    {
        return $this->bool('logAllToolCalls', true);
    }

    public function allowAnonymousReadOnly(): bool
    {
        return $this->bool('allowAnonymousReadOnly', false);
    }

    public function enforcePkce(): bool
    {
        return $this->bool('enforcePkce', true);
    }

    public function mtlsValidationEnabled(): bool
    {
        return $this->bool('mtlsValidationEnabled', false);
    }

    public function mtlsCaCertificate(): string
    {
        return $this->string('mtlsCaCertificate', '');
    }

    public function mcpServerOnlineSince(): int
    {
        return $this->int('mcpServerOnlineSince', 0);
    }

    public function oauthDefaultScopes(): string
    {
        return $this->string('oauthDefaultScopes', 'mcp:read mcp:write mcp:tools');
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'enableMcpServer' => $this->isMcpServerEnabled() ? 1 : 0,
            'requireAuth' => $this->requireAuth() ? 1 : 0,
            'rateLimitGlobal' => $this->rateLimitGlobal() ? 1 : 0,
            'logAllToolCalls' => $this->logAllToolCalls() ? 1 : 0,
            'allowAnonymousReadOnly' => $this->allowAnonymousReadOnly() ? 1 : 0,
            'enforcePkce' => $this->enforcePkce() ? 1 : 0,
            'mtlsCaCertificate' => $this->mtlsCaCertificate(),
            'mtlsValidationEnabled' => $this->mtlsValidationEnabled() ? 1 : 0,
            'mcpServerOnlineSince' => $this->mcpServerOnlineSince(),
            'oauthDefaultClientId' => $this->string('oauthDefaultClientId', 'typo3-ai-foundation-mcp-client'),
            'oauthDefaultRedirectUris' => $this->string('oauthDefaultRedirectUris', ''),
            'oauthDefaultScopes' => $this->oauthDefaultScopes(),
            'oauthMaxActiveTokensPerUser' => $this->int('oauthMaxActiveTokensPerUser', 5),
            'accessTokenLifetime' => $this->int('accessTokenLifetime', 3600),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(array $settings): void
    {
        $normalized = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[$key] = (string) $value;
        }
        $this->extensionSettingsService->merge(self::EXTENSION_KEY, $normalized);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->extensionSettingsService->getAll(self::EXTENSION_KEY);
    }

    private function bool(string $key, bool $default): bool
    {
        $config = $this->config();
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        return filter_var($config[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private function string(string $key, string $default): string
    {
        $config = $this->config();

        return is_string($config[$key] ?? null) ? $config[$key] : $default;
    }

    private function int(string $key, int $default): int
    {
        $config = $this->config();

        return is_numeric($config[$key] ?? null) ? (int) $config[$key] : $default;
    }
}
