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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use Psr\Log\LoggerInterface;

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
        private readonly ?LocalReceiptCache $localReceiptCache = null,
        private readonly ?LoggerInterface $logger = null,
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

    /**
     * True when Activate must collect name/email (no stored bearer and no license contact).
     */
    public function needsContactForActivation(): bool
    {
        $token = $this->runtimeSettings->getTokenPlain();
        if ($token !== null && $token !== '') {
            return false;
        }

        return !$this->licenseContactResolver->hasContact();
    }

    /**
     * `token_ip_conflict`: BindIp for a stored bearer collides with the account that owns this server IP.
     */
    public static function isIpConflict(CreditsApiException $exception): bool
    {
        return $exception->errorCode === CreditsApiErrorCodes::TOKEN_IP_CONFLICT;
    }

    /**
     * `token_ip_paid_conflict`: this server's IP belongs to a paying customer's account.
     *
     * Must never be treated as an ordinary conflict. Adopting would hand this install someone
     * else's paid credits, and `adoptIpBoundAccount()` clears the stored bearer before
     * re-minting — so on shared hosting a site would destroy its own token and receive an
     * unrelated trial account.
     */
    public static function isPaidIpConflict(CreditsApiException $exception): bool
    {
        return $exception->errorCode === CreditsApiErrorCodes::TOKEN_IP_PAID_CONFLICT;
    }

    /**
     * Redeem an emailed claim code and store the Bearer it returns.
     *
     * @param array{name?: string, email?: string}|null $contactOverride Form contact when no license; null = resolve from ns_license
     *
     * @return array{action: string, token: string, bound_ip: string, merged: bool}
     */
    public function claimWithCode(#[\SensitiveParameter] string $claimCode, ?array $contactOverride = null): array
    {
        $claimCode = trim($claimCode);
        if ($claimCode === '') {
            throw new CreditsApiException(
                CreditsApiErrorCodes::REQUIRED_FIELD_MISSING,
                400,
                'A claim code is required.',
            );
        }

        $previousToken = (string) ($this->runtimeSettings->getTokenPlain() ?? '');

        $payload = $this->apiClient->claimToken($claimCode, null, $this->resolveContact($contactOverride));
        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            throw new CreditsApiException('token_missing', 502, 'Claim endpoint did not return a token');
        }

        // Replace whatever was stored: on a merge the server hands back the surviving account's
        // bearer, which may differ from the one the code was issued for.
        $this->runtimeSettings->storeToken($token);
        $this->rememberToken($token);

        if ($previousToken !== '' && !hash_equals($previousToken, $token)) {
            // This site now belongs to a different account, so the locally mirrored receipts
            // describe the previous one's spend. Left in place they are reported as this
            // account's usage. Deliberately keyed on the token changing: a re-bind to a new
            // server, or a merge that returns the site's own bearer, keeps its history.
            $this->apiResponseCache->flush();
            try {
                $this->localReceiptCache?->purgeAll();
            } catch (\Throwable $exception) {
                // A stale usage card must not cost the customer their claim.
                $this->logger?->warning('Could not purge local receipts after claim', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'action' => (string) ($payload['action'] ?? 'claimed'),
            'token' => $token,
            'bound_ip' => (string) ($payload['bound_ip'] ?? ''),
            'merged' => (bool) ($payload['merged_into_existing_account'] ?? false),
        ];
    }

    /**
     * Ask the server to email a fresh claim code to the account's registered address.
     *
     * The response is deliberately uninformative about whether the address is registered.
     */
    public function requestClaimCode(string $email): void
    {
        $this->apiClient->requestClaimCode($email);
    }

    /**
     * Replace the stored bearer with the token of the account bound to this server IP.
     *
     * @param array{name?: string, email?: string}|null $contactOverride
     */
    public function adoptIpBoundAccount(?array $contactOverride = null): string
    {
        $this->invalidate();

        return $this->issueFreshToken($contactOverride);
    }

    /**
     * @param array{name?: string, email?: string}|null $contactOverride Form contact when no license; null = resolve from ns_license
     */
    public function issueFreshToken(?array $contactOverride = null): string
    {
        $payload = $this->apiClient->issueTrialToken(null, $this->resolveContact($contactOverride));
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
     * @param array{name?: string, email?: string}|null $contactOverride
     *
     * @return array{action: 'minted'|'unchanged', token: string, already_bound?: bool, bound_ip?: string}
     */
    public function activateTrialToken(?array $contactOverride = null): array
    {
        $token = $this->runtimeSettings->getTokenPlain();
        if ($token !== null && $token !== '') {
            try {
                $bind = $this->ensureIpBound($token);
            } catch (CreditsApiException $exception) {
                if (self::isPaidIpConflict($exception)) {
                    // This IP belongs to a paying customer (shared hosting). Adopting would take
                    // their credits, and the adopt path clears the stored bearer first — so keep
                    // the token exactly as it is and surface the error for the admin to act on.
                    throw $exception;
                }
                if (!self::isIpConflict($exception)) {
                    throw $exception;
                }

                // This server IP already belongs to another (trial) account, so the stored bearer can
                // never bind. The server mints idempotently per observed IP: dropping the bearer and
                // asking again returns that IP owner's token (one trial account per public IP).
                return [
                    'action' => 'adopted',
                    'token' => $this->adoptIpBoundAccount($contactOverride),
                ];
            }

            return [
                'action' => 'unchanged',
                'token' => $token,
                'already_bound' => true,
                'bound_ip' => $bind['bound_ip'] ?? '',
            ];
        }

        $token = $this->issueFreshToken($contactOverride);

        return [
            'action' => 'minted',
            'token' => $token,
        ];
    }

    /**
     * @param array{name?: string, email?: string}|null $contactOverride
     *
     * @return array{name?: string, email?: string}
     */
    private function resolveContact(?array $contactOverride): array
    {
        if ($contactOverride !== null) {
            return self::normalizeContact($contactOverride);
        }

        return $this->licenseContactResolver->resolve();
    }

    /**
     * @param array{name?: string, email?: string} $contact
     *
     * @return array{name?: string, email?: string}
     */
    public static function normalizeContact(array $contact): array
    {
        $out = [];
        $name = trim((string) ($contact['name'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));
        if ($name !== '') {
            $out['name'] = $name;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out['email'] = $email;
        }

        return $out;
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
