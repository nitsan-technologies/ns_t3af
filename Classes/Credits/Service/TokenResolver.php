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

use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;

/**
 * @internal
 */
final class TokenResolver
{
    private const CACHE_IDENTIFIER = 't3planet_bearer_token';

    public function __construct(
        private readonly T3PlanetApiClient $apiClient,
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly LicenseKeyResolver $licenseKeyResolver,
        private readonly CacheFacadeInterface $cache,
        private readonly CreditsApiResponseCacheInterface $apiResponseCache,
    ) {}

    public function resolve(?string $domain = null): string
    {
        $cached = $this->cache->get(self::CACHE_IDENTIFIER);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // 1) Extension settings, 2) database (see RuntimeSettingsService::getTokenPlain).
        $stored = $this->runtimeSettings->getTokenPlain();
        if ($stored !== null && $stored !== '') {
            $this->rememberToken($stored);

            return $stored;
        }

        // 3) Issue via license keys + domain.
        return $this->issueFreshToken($domain);
    }

    public function issueFreshToken(?string $domain = null): string
    {
        $licenseKeys = $this->runtimeSettings->getLicenseKeys();
        if ($licenseKeys === '') {
            throw new CreditsApiException('license_keys_missing', 400, 'No license keys configured for T3Planet Credits');
        }

        $domain ??= $this->domainResolver->resolve();
        $payload = $this->apiClient->issueToken($licenseKeys, $domain);
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            throw new CreditsApiException('token_missing', 502, 'Token endpoint did not return a token');
        }

        $this->runtimeSettings->storeToken($token);
        $this->runtimeSettings->storeCreditsDomain($domain);
        $this->rememberToken($token);

        return $token;
    }

    /**
     * Mints a Bearer via Token.php when none exists; otherwise attaches only licence keys
     * not yet stored in runtime (POST AttachLicenses).
     *
     * @return array{
     *   action: 'minted'|'attached'|'unchanged',
     *   license_keys: string,
     *   token?: string,
     *   newly_attached?: list<string>,
     *   credits_added?: int,
     *   already_bound?: bool
     * }
     */
    public function syncLicensePool(string $discoveredLicenseKeys, ?string $domain = null): array
    {
        $discoveredLicenseKeys = trim($discoveredLicenseKeys);
        if ($discoveredLicenseKeys === '') {
            throw new CreditsApiException(
                CreditsApiErrorCodes::NO_LICENSES,
                400,
                'No T3Planet license keys found',
            );
        }

        $domain ??= $this->domainResolver->resolve();
        $stored = $this->runtimeSettings->getLicenseKeys();
        $token = $this->runtimeSettings->getTokenPlain();

        if ($token === null || $token === '') {
            $this->runtimeSettings->save(['license_keys' => $discoveredLicenseKeys]);
            $token = $this->issueFreshToken($domain);

            return [
                'action' => 'minted',
                'token' => $token,
                'license_keys' => $discoveredLicenseKeys,
            ];
        }

        $newKeys = $this->licenseKeyResolver->buildNewLicenseKeysCommaSeparated($discoveredLicenseKeys, $stored);
        if ($newKeys === '') {
            if ($stored !== $discoveredLicenseKeys) {
                $this->runtimeSettings->save(['license_keys' => $discoveredLicenseKeys]);
            }

            return [
                'action' => 'unchanged',
                'license_keys' => $this->runtimeSettings->getLicenseKeys(),
                'already_bound' => true,
            ];
        }

        $payload = $this->apiClient->attachLicenses($domain, $newKeys, $token);
        $canonical = trim((string) ($payload['license_keys'] ?? ''));
        if ($canonical === '') {
            $canonical = $discoveredLicenseKeys;
        }
        $this->runtimeSettings->save(['license_keys' => $canonical]);

        if ((int) ($payload['credits_added'] ?? 0) > 0) {
            $this->apiResponseCache->flush();
        }

        $newlyAttached = $payload['newly_attached'] ?? [];
        if (!is_array($newlyAttached)) {
            $newlyAttached = [];
        }

        return [
            'action' => 'attached',
            'license_keys' => $canonical,
            'newly_attached' => array_values(array_map('strval', $newlyAttached)),
            'credits_added' => (int) ($payload['credits_added'] ?? 0),
            'already_bound' => (bool) ($payload['already_bound'] ?? false),
        ];
    }

    public function invalidate(): void
    {
        $this->cache->remove(self::CACHE_IDENTIFIER);
        $this->runtimeSettings->clearToken();
        $this->apiResponseCache->flush();
    }

    /**
     * Clears a cached bearer token rejected by the T3Planet API (same rules as {@see ProxyAiExecutor}).
     */
    public function invalidateOnUnauthorized(CreditsApiException $exception): bool
    {
        if ($exception->httpStatus !== 401 && $exception->errorCode !== CreditsApiErrorCodes::TOKEN_INVALID) {
            return false;
        }

        $this->invalidate();

        return true;
    }

    private function rememberToken(string $token): void
    {
        $this->cache->set(self::CACHE_IDENTIFIER, $token, [], 3600);
    }
}
