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

namespace NITSAN\NsT3AF\Service;

/**
 * Host/PHP preflight checks for AI Foundation (credentials encryption + HTTP).
 *
 * Product checklist covers onboarding; this service covers environment gaps that
 * otherwise surface as opaque 500/503 responses when saving API keys.
 *
 * @internal
 */
final class EnvironmentRequirementService
{
    public const CODE_SODIUM = 'sodium';

    public const CODE_ENCRYPTION_KEY = 'encryptionKey';

    public const CODE_CURL = 'curl';

    /**
     * Local/DDEV developer toggle only. Never required in production.
     * Enable via `ddev sodium-off` (sets env in `.ddev/config.nosodium.yaml`).
     * Honored only when running under DDEV or TYPO3 Development context.
     */
    public const REPRO_ENV = 'T3AF_REPRO_NO_SODIUM';

    public function isCipherReady(): bool
    {
        return $this->hasSodium() && $this->hasEncryptionKey();
    }

    public function isReady(): bool
    {
        return $this->isCipherReady() && $this->hasCurl();
    }

    public function hasSodium(): bool
    {
        if ($this->isLocalSodiumReproForced()) {
            return false;
        }

        return extension_loaded('sodium') && \defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES');
    }

    public function hasEncryptionKey(): bool
    {
        return trim((string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '')) !== '';
    }

    public function hasCurl(): bool
    {
        return extension_loaded('curl');
    }

    /**
     * Checklist rows for failing requirements only (prepended by SetupChecklistService).
     *
     * @return list<array{status: string, titleKey: string, descKey: string, actionRoute: string, actionTabKey?: string|null}>
     */
    public function failingChecklistItems(): array
    {
        $items = [];

        if (!$this->hasSodium()) {
            $items[] = [
                'status' => 'error',
                'titleKey' => 'checklist.env.sodium.title',
                'descKey' => 'checklist.env.sodium.descMissing',
                'actionRoute' => '',
                'actionTabKey' => '',
            ];
        }

        if (!$this->hasEncryptionKey()) {
            $items[] = [
                'status' => 'error',
                'titleKey' => 'checklist.env.encryptionKey.title',
                'descKey' => 'checklist.env.encryptionKey.descMissing',
                'actionRoute' => 't3af_dashboard.providers',
                'actionTabKey' => 'providers',
            ];
        }

        if (!$this->hasCurl()) {
            $items[] = [
                'status' => 'error',
                'titleKey' => 'checklist.env.curl.title',
                'descKey' => 'checklist.env.curl.descMissing',
                'actionRoute' => 't3af_dashboard.overview',
                'actionTabKey' => 'dashboard',
            ];
        }

        return $items;
    }

    /**
     * Localized banner payloads for credential-related gaps only (AI Providers tab).
     *
     * @return list<array{code: string, title: string, description: string}>
     */
    public function failingCipherAlerts(callable $translate): array
    {
        return array_values(array_filter(
            $this->failingAlerts($translate),
            static fn(array $alert): bool => in_array(
                $alert['code'],
                [self::CODE_SODIUM, self::CODE_ENCRYPTION_KEY],
                true,
            ),
        ));
    }

    /**
     * Localized banner payloads for the module shell (empty when healthy).
     *
     * @return list<array{code: string, title: string, description: string}>
     */
    public function failingAlerts(callable $translate): array
    {
        $alerts = [];
        foreach ($this->failingChecklistItems() as $item) {
            $code = match ($item['titleKey']) {
                'checklist.env.sodium.title' => self::CODE_SODIUM,
                'checklist.env.encryptionKey.title' => self::CODE_ENCRYPTION_KEY,
                'checklist.env.curl.title' => self::CODE_CURL,
                default => 'unknown',
            };
            $alerts[] = [
                'code' => $code,
                'title' => (string) $translate($item['titleKey']),
                'description' => (string) $translate($item['descKey']),
            ];
        }

        return $alerts;
    }

    /**
     * Simulate missing Sodium for local UX testing only (DDEV / Development).
     * Ignored in Production even if the env var is set by mistake.
     */
    private function isLocalSodiumReproForced(): bool
    {
        if (getenv(self::REPRO_ENV) !== '1') {
            return false;
        }

        $ddevProject = getenv('DDEV_PROJECT');
        if (is_string($ddevProject) && $ddevProject !== '') {
            return true;
        }

        $context = (string) (getenv('TYPO3_CONTEXT') ?: '');

        return str_starts_with($context, 'Development');
    }
}
