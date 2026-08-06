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
        private readonly CacheFacadeInterface $cache,
        private readonly CreditsApiResponseCacheInterface $apiResponseCache,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly LicenseContactResolver $licenseContactResolver,
    ) {}

    public function resolve(?string $domain = null): string
    {
        $cached = $this->cache->get(self::CACHE_IDENTIFIER);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $stored = $this->runtimeSettings->getTokenPlain();
        if ($stored !== null && $stored !== '') {
            $this->rememberToken($stored);

            return $stored;
        }

        return $this->issueFreshToken();
    }

    public function issueFreshToken(): string
    {
        $payload = $this->apiClient->issueTrialToken(null, $this->licenseContactResolver->resolve());
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            throw new CreditsApiException('token_missing', 502, 'Token endpoint did not return a token');
        }

        $this->runtimeSettings->storeToken($token);
        $this->rememberToken($token);

        return $token;
    }

    /**
     * Ensures a bearer exists for IP-bound trial credits (idempotent mint on the server).
     *
     * @return array{action: 'minted'|'unchanged', token: string, already_bound?: bool, bound_ip?: string}
     */
    public function activateTrialToken(): array
    {
        $token = $this->runtimeSettings->getTokenPlain();
        if ($token !== null && $token !== '') {
            $bind = $this->ensureIpBound($token);

            return [
                'action' => 'unchanged',
                'token' => $token,
                'already_bound' => true,
                'bound_ip' => $bind['bound_ip'] ?? '',
            ];
        }

        $token = $this->issueFreshToken();

        return [
            'action' => 'minted',
            'token' => $token,
        ];
    }

    /**
     * Write-once BindIp for legacy tokens missing bound_ip.
     *
     * @return array{bound_ip: string, already_bound: bool}
     */
    public function ensureIpBound(#[\SensitiveParameter] ?string $bearerToken = null): array
    {
        $token = $bearerToken ?? $this->runtimeSettings->getTokenPlain();
        if ($token === null || $token === '') {
            throw new CreditsApiException('token_missing', 401, 'No stored bearer token to bind IP');
        }

        $domain = $this->domainResolver->resolve();
        $payload = $this->apiClient->bindIp($token, $domain);
        $boundIp = trim((string) ($payload['bound_ip'] ?? ''));
        if ($boundIp === '') {
            throw new CreditsApiException('internal_error', 502, 'BindIp endpoint did not return bound_ip');
        }

        return [
            'bound_ip' => $boundIp,
            'already_bound' => (bool) ($payload['already_bound'] ?? false),
        ];
    }

    /**
     * Replace exposed Bearer with a new secret on the same IP-bound account.
     */
    public function refreshBearerToken(): string
    {
        $current = $this->runtimeSettings->getTokenPlain();
        if ($current === null || $current === '') {
            throw new CreditsApiException('token_missing', 401, 'No stored bearer token to refresh');
        }

        $this->ensureIpBound($current);
        $domain = $this->domainResolver->resolve();
        $payload = $this->apiClient->refreshBearerToken($current, $domain, $this->licenseContactResolver->resolve());
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            throw new CreditsApiException('token_missing', 502, 'RefreshToken endpoint did not return a token');
        }

        $this->runtimeSettings->storeToken($token);
        $this->rememberToken($token);
        $this->apiResponseCache->flush();

        return $token;
    }

    /**
     * Drop cached/stored bearer and re-fetch the server token for this IP (idempotent).
     */
    public function resyncFromServer(): string
    {
        $this->invalidate();

        return $this->issueFreshToken();
    }

    public function invalidate(): void
    {
        $this->cache->remove(self::CACHE_IDENTIFIER);
        $this->runtimeSettings->clearToken();
        $this->apiResponseCache->flush();
    }

    public function invalidateOnUnauthorized(CreditsApiException $exception): bool
    {
        if (
            $exception->httpStatus !== 401
            && $exception->httpStatus !== 403
            && !in_array($exception->errorCode, [
                CreditsApiErrorCodes::TOKEN_INVALID,
                CreditsApiErrorCodes::TOKEN_IP_MISMATCH,
            ], true)
        ) {
            return false;
        }

        if ($exception->errorCode === CreditsApiErrorCodes::TOKEN_IP_MISMATCH) {
            return false;
        }

        $this->invalidate();

        return true;
    }

    private function rememberToken(#[\SensitiveParameter] string $token): void
    {
        $this->cache->set(self::CACHE_IDENTIFIER, $token, [], 3600);
    }
}
