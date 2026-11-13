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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Settings\ExtensionSettingsService;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class AdvancedSettingsService
{
    private const EXTENSION_KEY = 'ns_t3af';

    /** Default MCP Streamable HTTP POST body limit (100 MiB). */
    public const DEFAULT_MAX_BODY_BYTES = 100 * 1024 * 1024;

    /** Default max file size for MCP uploads / URL downloads (MiB). */
    public const DEFAULT_MAX_FILE_SIZE_MB = 500;

    /** Default single-use upload token lifetime (seconds). */
    public const DEFAULT_UPLOAD_TOKEN_TTL = 900;

    /** Default time a records_apply requestId is remembered (hours). */
    public const DEFAULT_IDEMPOTENCY_TTL_HOURS = 24;

    /** Default max decoded base64 payload accepted inline via MCP tools (bytes). */
    public const DEFAULT_MAX_BASE64_UPLOAD_BYTES = 100 * 1024 * 1024;

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

    public function maxBodyBytes(): int
    {
        $configured = $this->int('mcpMaxBodyBytes', self::DEFAULT_MAX_BODY_BYTES);
        if ($configured < 1) {
            return self::DEFAULT_MAX_BODY_BYTES;
        }

        return $configured;
    }

    public function maxFileSizeMb(): int
    {
        $configured = $this->int('mcpMaxFileSizeMb', self::DEFAULT_MAX_FILE_SIZE_MB);
        if ($configured < 1) {
            return self::DEFAULT_MAX_FILE_SIZE_MB;
        }

        return $configured;
    }

    public function allowDestructiveFileOps(): bool
    {
        return $this->bool('mcpAllowDestructiveFileOps', true);
    }

    /** Mark records written through MCP tools as AI-involved in the AI Label module. */
    public function markWritesAsAi(): bool
    {
        return $this->bool('mcpMarkWritesAsAi', true);
    }

    /** How long records_apply remembers a requestId and its answer, in seconds. */
    public function idempotencyTtlSeconds(): int
    {
        $hours = $this->int('mcpIdempotencyTtlHours', self::DEFAULT_IDEMPOTENCY_TTL_HOURS);

        return ($hours < 1 ? self::DEFAULT_IDEMPOTENCY_TTL_HOURS : $hours) * 3600;
    }

    public function uploadTokenTtl(): int
    {
        $configured = $this->int('mcpUploadTokenTtl', self::DEFAULT_UPLOAD_TOKEN_TTL);
        if ($configured < 1) {
            return self::DEFAULT_UPLOAD_TOKEN_TTL;
        }

        return $configured;
    }

    public function maxBase64UploadBytes(): int
    {
        $configured = $this->int('mcpMaxBase64UploadBytes', self::DEFAULT_MAX_BASE64_UPLOAD_BYTES);
        if ($configured < 1) {
            return self::DEFAULT_MAX_BASE64_UPLOAD_BYTES;
        }

        return $configured;
    }

    public function oauthDefaultScopes(): string
    {
        return $this->string('oauthDefaultScopes', 'mcp:read mcp:write mcp:tools');
    }

    public function oauthDefaultRedirectUris(): string
    {
        return $this->string('oauthDefaultRedirectUris', '');
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
            'mcpMaxBodyBytes' => $this->maxBodyBytes(),
            'mcpMaxFileSizeMb' => $this->maxFileSizeMb(),
            'mcpAllowDestructiveFileOps' => $this->allowDestructiveFileOps() ? 1 : 0,
            'mcpMarkWritesAsAi' => $this->markWritesAsAi() ? 1 : 0,
            'mcpIdempotencyTtlHours' => intdiv($this->idempotencyTtlSeconds(), 3600),
            'mcpUploadTokenTtl' => $this->uploadTokenTtl(),
            'mcpMaxBase64UploadBytes' => $this->maxBase64UploadBytes(),
        ];
    }

    /** @var list<string> */
    public const ALLOWED_OAUTH_SCOPES = ['mcp:read', 'mcp:write', 'mcp:tools'];

    private const MIN_ACCESS_TOKEN_LIFETIME = 60;
    private const MAX_ACCESS_TOKEN_LIFETIME = 31536000;
    private const MIN_MAX_BODY_BYTES = 4194304;
    private const MAX_MAX_BODY_BYTES = 536870912;
    private const MIN_MAX_FILE_SIZE_MB = 1;
    private const MAX_MAX_FILE_SIZE_MB = 10240;
    private const MIN_UPLOAD_TOKEN_TTL = 60;
    private const MAX_UPLOAD_TOKEN_TTL = 86400;
    private const MIN_MAX_BASE64_UPLOAD_BYTES = 1024;
    private const MAX_MAX_BASE64_UPLOAD_BYTES = 536870912;
    private const MIN_MAX_ACTIVE_TOKENS = 1;
    private const MAX_MAX_ACTIVE_TOKENS = 100;

    /**
     * Validate and persist Advanced MCP settings. Rejects out-of-range numbers,
     * unknown OAuth scopes, and unparseable redirect URI lines.
     *
     * @param array<string, mixed> $settings
     * @return array{ok: bool, message: string, settings: array<string, mixed>}
     */
    public function save(array $settings): array
    {
        $validated = $this->validateIncoming($settings);
        if ($validated['ok'] !== true) {
            return [
                'ok' => false,
                'message' => $validated['message'],
                'settings' => $this->all(),
            ];
        }

        $this->extensionSettingsService->mergeGlobal(self::EXTENSION_KEY, $validated['normalized']);

        return [
            'ok' => true,
            'message' => '',
            'settings' => $this->all(),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{ok: bool, message: string, normalized: array<string, string>}
     */
    private function validateIncoming(array $settings): array
    {
        $normalized = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[$key] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        $intChecks = [
            'accessTokenLifetime' => [self::MIN_ACCESS_TOKEN_LIFETIME, self::MAX_ACCESS_TOKEN_LIFETIME, 'Access token lifetime'],
            'mcpMaxBodyBytes' => [self::MIN_MAX_BODY_BYTES, self::MAX_MAX_BODY_BYTES, 'Max MCP POST body size'],
            'mcpMaxFileSizeMb' => [self::MIN_MAX_FILE_SIZE_MB, self::MAX_MAX_FILE_SIZE_MB, 'Max MCP file size'],
            'mcpUploadTokenTtl' => [self::MIN_UPLOAD_TOKEN_TTL, self::MAX_UPLOAD_TOKEN_TTL, 'MCP upload token lifetime'],
            'mcpMaxBase64UploadBytes' => [self::MIN_MAX_BASE64_UPLOAD_BYTES, self::MAX_MAX_BASE64_UPLOAD_BYTES, 'Max inline base64 upload'],
            'oauthMaxActiveTokensPerUser' => [self::MIN_MAX_ACTIVE_TOKENS, self::MAX_MAX_ACTIVE_TOKENS, 'Max active tokens per user'],
        ];
        foreach ($intChecks as $field => [$min, $max, $label]) {
            if (!array_key_exists($field, $normalized)) {
                continue;
            }
            $raw = trim($normalized[$field]);
            if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
                return ['ok' => false, 'message' => sprintf('%s must be a whole number.', $label), 'normalized' => []];
            }
            $intValue = (int) $raw;
            if ($intValue < $min || $intValue > $max) {
                return [
                    'ok' => false,
                    'message' => sprintf('%s must be between %d and %d.', $label, $min, $max),
                    'normalized' => [],
                ];
            }
            $normalized[$field] = (string) $intValue;
        }

        if (array_key_exists('oauthDefaultScopes', $normalized)) {
            $scopesError = $this->validateOauthScopes($normalized['oauthDefaultScopes']);
            if ($scopesError !== null) {
                return ['ok' => false, 'message' => $scopesError, 'normalized' => []];
            }
            $normalized['oauthDefaultScopes'] = $this->normalizeOauthScopes($normalized['oauthDefaultScopes']);
        }

        if (array_key_exists('oauthDefaultRedirectUris', $normalized)) {
            $urisError = $this->validateOauthRedirectUris($normalized['oauthDefaultRedirectUris']);
            if ($urisError !== null) {
                return ['ok' => false, 'message' => $urisError, 'normalized' => []];
            }
        }

        return ['ok' => true, 'message' => '', 'normalized' => $normalized];
    }

    private function validateOauthScopes(string $raw): ?string
    {
        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $t): bool => $t !== ''));
        if ($tokens === []) {
            return 'Default OAuth scopes must include at least one allowed scope (mcp:read, mcp:write, mcp:tools).';
        }
        $allowed = array_fill_keys(self::ALLOWED_OAUTH_SCOPES, true);
        foreach ($tokens as $token) {
            if (!isset($allowed[$token])) {
                return sprintf('Unknown OAuth scope "%s". Allowed: %s.', $token, implode(', ', self::ALLOWED_OAUTH_SCOPES));
            }
        }

        return null;
    }

    private function normalizeOauthScopes(string $raw): string
    {
        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        $unique = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token !== '' && !isset($unique[$token])) {
                $unique[$token] = true;
            }
        }

        return implode(' ', array_keys($unique));
    }

    private function validateOauthRedirectUris(string $raw): ?string
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        foreach ($lines as $line) {
            $entry = trim($line);
            if ($entry === '') {
                continue;
            }
            if (!$this->isValidRedirectUriAllowListEntry($entry)) {
                return sprintf('Invalid OAuth redirect URI "%s". Use absolute URIs or patterns like https://host/path* or http://127.0.0.1:*.', $entry);
            }
        }

        return null;
    }

    private function isValidRedirectUriAllowListEntry(string $entry): bool
    {
        if (str_ends_with($entry, '*')) {
            $prefix = substr($entry, 0, -1);
            if ($prefix === 'cursor://' || strcasecmp($entry, 'cursor:*') === 0) {
                return true;
            }
            if (preg_match('#^https?://#i', $prefix) === 1) {
                $parts = parse_url(rtrim($prefix, '/') . '/');
                return is_array($parts)
                    && isset($parts['scheme'], $parts['host'])
                    && is_string($parts['scheme'])
                    && is_string($parts['host'])
                    && $parts['host'] !== ''
                    && !isset($parts['user'])
                    && !isset($parts['pass']);
            }

            return false;
        }

        $parts = parse_url($entry);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && is_string($parts['scheme'])
            && is_string($parts['host'])
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return $this->extensionSettingsService->getAllIgnorePid(self::EXTENSION_KEY);
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
