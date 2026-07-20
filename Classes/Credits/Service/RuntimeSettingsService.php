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

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Service\CredentialCipher;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @internal
 */
final class RuntimeSettingsService
{
    private const EXTENSION_KEY = 'ns_t3af';

    private const EXT_TOKEN_KEY = 't3planetApiToken';

    private const EXT_CREDITS_DOMAIN_KEY = 't3planetCreditsDomain';

    /** @deprecated since ns_t3af 1.x — migrated from ext_conf to runtime row on sync */
    private const LEGACY_EXT_API_BASE_KEY = 't3planetApiBaseUrl';

    public function __construct(
        private readonly RuntimeSettingsRepository $repository,
        private readonly CredentialCipher $cipher,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly CreditsApiBaseUrlResolver $apiBaseUrlResolver = new CreditsApiBaseUrlResolver(),
    ) {}

    public function isCreditModeEnabled(): bool
    {
        return (int) ($this->findSingleton()['credit_mode'] ?? 0) === 1;
    }

    public function getLicenseKeys(): string
    {
        return trim((string) ($this->findSingleton()['license_keys'] ?? ''));
    }

    public function getSelectedLicenseExtKey(): string
    {
        $key = (string) ($this->findSingleton()['selected_license_ext_key'] ?? 'ns_t3af');

        return $key !== '' ? $key : 'ns_t3af';
    }

    public function getApiBaseUrl(): string
    {
        $this->ensureRow();
        $this->syncApiBaseUrlIfNeeded();
        $row = $this->repository->findSingleton() ?? [];
        $fromRow = trim((string) ($row['t3planet_api_base_url'] ?? ''));

        return $fromRow !== ''
            ? $this->apiBaseUrlResolver->normalize($fromRow)
            : $this->apiBaseUrlResolver->resolve();
    }

    /**
     * Hostname bound to the T3Planet Credits token (set at activation / first backend request).
     */
    public function getCreditsDomain(): string
    {
        return strtolower(trim((string) ($this->findSingleton()['credits_domain'] ?? '')));
    }

    public function storeCreditsDomain(string $domain): void
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || $domain === 'localhost') {
            return;
        }

        if (str_contains($domain, ':')) {
            $domain = GeneralUtility::trimExplode(':', $domain, true)[0] ?? $domain;
        }

        $this->save(['credits_domain' => $domain]);
    }

    /**
     * Optional Extension Configuration override when no HTTP context and site base is path-only.
     */
    public function getCreditsDomainOverride(): string
    {
        try {
            $domain = trim((string) ($this->extensionConfiguration->get(self::EXTENSION_KEY, self::EXT_CREDITS_DOMAIN_KEY) ?? ''));
        } catch (\Throwable) {
            return '';
        }

        return strtolower($domain);
    }

    /**
     * Resolves bearer token without calling Token API.
     * Order: extension configuration → encrypted database field.
     */
    public function getTokenPlain(): ?string
    {
        $fromExtension = $this->getTokenFromExtensionConfiguration();
        if ($fromExtension !== null) {
            return $fromExtension;
        }

        return $this->getTokenFromDatabase();
    }

    /**
     * Persists token to encrypted DB row and extension configuration (for support reference).
     */
    public function storeToken(#[\SensitiveParameter] string $plainToken): void
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return;
        }

        $this->ensureRow();
        $this->repository->updateSingleton([
            'token_enc' => $this->cipher->encrypt($plainToken),
            'activated_at' => time(),
        ]);
        $this->persistTokenToExtensionConfiguration($plainToken);
    }

    public function clearToken(): void
    {
        $this->ensureRow();
        $this->repository->updateSingleton(['token_enc' => '']);
        $this->clearTokenFromExtensionConfiguration();
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    public function save(array $fields): void
    {
        $this->ensureRow();
        $this->repository->updateSingleton($fields);
    }

    public function touchBalanceSynced(): void
    {
        $this->ensureRow();
        $this->repository->updateSingleton(['last_balance_synced' => time()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function findSingleton(): array
    {
        $this->ensureRow();

        return $this->repository->findSingleton() ?? [];
    }

    private function getTokenFromExtensionConfiguration(): ?string
    {
        try {
            $token = trim((string) ($this->extensionConfiguration->get(self::EXTENSION_KEY, self::EXT_TOKEN_KEY) ?? ''));
        } catch (\Throwable) {
            return null;
        }

        return $token !== '' ? $token : null;
    }

    private function getTokenFromDatabase(): ?string
    {
        $enc = (string) ($this->findSingleton()['token_enc'] ?? '');
        if ($enc === '') {
            return null;
        }

        try {
            $plain = trim($this->cipher->decrypt($enc));

            return $plain !== '' ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function persistTokenToExtensionConfiguration(#[\SensitiveParameter] string $plainToken): void
    {
        try {
            $configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY);
            if (!is_array($configuration)) {
                $configuration = [];
            }
            $configuration[self::EXT_TOKEN_KEY] = $plainToken;
            $this->extensionConfiguration->set(self::EXTENSION_KEY, $configuration);
        } catch (\Throwable) {
            // Non-fatal: DB token remains authoritative for runtime.
        }
    }

    private function clearTokenFromExtensionConfiguration(): void
    {
        try {
            $configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY);
            if (!is_array($configuration)) {
                return;
            }
            unset($configuration[self::EXT_TOKEN_KEY]);
            $this->extensionConfiguration->set(self::EXTENSION_KEY, $configuration);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function ensureRow(): void
    {
        if ($this->repository->findSingleton() === null) {
            $this->repository->insertSingleton();
        }
    }

    private function syncApiBaseUrlIfNeeded(): void
    {
        $row = $this->repository->findSingleton();
        if ($row === null) {
            return;
        }

        $stored = $this->apiBaseUrlResolver->normalize((string) ($row['t3planet_api_base_url'] ?? ''));
        if ($stored === '') {
            $stored = $this->readLegacyExtensionConfigApiBaseUrl();
        }

        $target = $this->apiBaseUrlResolver->resolve();

        if ($stored !== '' && !$this->apiBaseUrlResolver->isKnownBuiltInUrl($stored)) {
            return;
        }

        if ($stored === $target) {
            return;
        }

        $this->repository->updateSingleton(['t3planet_api_base_url' => $target]);
    }

    private function readLegacyExtensionConfigApiBaseUrl(): string
    {
        try {
            $url = trim((string) ($this->extensionConfiguration->get(self::EXTENSION_KEY, self::LEGACY_EXT_API_BASE_KEY) ?? ''));
        } catch (\Throwable) {
            return '';
        }

        if ($url === '' || !$this->apiBaseUrlResolver->isKnownBuiltInUrl($url)) {
            return '';
        }

        return $this->apiBaseUrlResolver->normalize($url);
    }
}
