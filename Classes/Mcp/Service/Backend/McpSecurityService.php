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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Utility\McpIpMatcher;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Security settings facade: IP allowlist CRUD, OAuth scope/PKCE, and mTLS extension config.
 *
 * @internal
 */
readonly class McpSecurityService
{
    /** mTLS UI and enforcement are not available until a future release. */
    private const MTLS_FEATURE_ENABLED = false;

    public function __construct(
        private McpIpAllowlistRepository $ipAllowlistRepository,
        private AdvancedSettingsService $advancedSettingsService,
    ) {}

    /**
     * @return list<array{uid:int,label:string,cidr:string,enabled:bool,crdate:int}>
     */
    public function listIpAllowlist(): array
    {
        return $this->ipAllowlistRepository->findAll();
    }

    /**
     * @return list<array{uid:int,label:string,cidr:string,enabled:bool,crdate:int}>
     */
    public function listEnabledIpAllowlist(): array
    {
        return $this->ipAllowlistRepository->findEnabled();
    }

    public function addIpAllowlistEntry(string $label, string $cidr, bool $enabled = true): int
    {
        return $this->ipAllowlistRepository->insert($label, $cidr, $enabled);
    }

    public function updateIpAllowlistEntry(int $uid, string $label, string $cidr, bool $enabled): void
    {
        $this->ipAllowlistRepository->update($uid, $label, $cidr, $enabled);
    }

    public function removeIpAllowlistEntry(int $uid): void
    {
        $this->ipAllowlistRepository->delete($uid);
    }

    public function isIpAllowed(string $clientIp): bool
    {
        $entries = $this->listEnabledIpAllowlist();
        if ($entries === []) {
            return true;
        }

        if ($clientIp === '') {
            return false;
        }

        foreach ($entries as $entry) {
            if (McpIpMatcher::matches($clientIp, $entry['cidr'])) {
                return true;
            }
        }

        return false;
    }

    public function validateMtls(ServerRequestInterface $request): bool
    {
        if (!$this->isMtlsValidationEnabled()) {
            return true;
        }

        $serverParams = $request->getServerParams();
        $verify = (string) ($serverParams['SSL_CLIENT_VERIFY'] ?? '');

        if ($verify !== 'SUCCESS') {
            return false;
        }

        $clientCertPem = (string) ($serverParams['SSL_CLIENT_CERT'] ?? '');
        if ($clientCertPem === '') {
            return false;
        }

        $caPem = trim($this->getMtlsCaCertificate());
        if ($caPem === '') {
            return true;
        }

        return $this->verifyClientCertificateAgainstCa($clientCertPem, $caPem);
    }

    private function verifyClientCertificateAgainstCa(string $clientCertPem, string $caPem): bool
    {
        $clientCert = openssl_x509_read($clientCertPem);
        $caCert = openssl_x509_read($caPem);
        if ($clientCert === false || $caCert === false) {
            return false;
        }

        $caFile = tempnam(sys_get_temp_dir(), 'nsaiu_mcp_ca_');
        $clientFile = tempnam(sys_get_temp_dir(), 'nsaiu_mcp_client_');
        if ($caFile === false || $clientFile === false) {
            return false;
        }

        try {
            file_put_contents($caFile, $caPem);
            file_put_contents($clientFile, $clientCertPem);

            return openssl_x509_checkpurpose($clientCert, X509_PURPOSE_ANY, [$caFile]) === true;
        } finally {
            @unlink($caFile);
            @unlink($clientFile);
        }
    }

    public function isPkceEnforced(): bool
    {
        return $this->advancedSettingsService->enforcePkce();
    }

    public function getDefaultScopes(): string
    {
        return $this->advancedSettingsService->oauthDefaultScopes();
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function saveOAuthSettings(array $settings): void
    {
        $allowed = ['enforcePkce', 'oauthDefaultScopes'];
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $settings)) {
                $payload[$key] = (string) $settings[$key];
            }
        }

        if ($payload !== []) {
            $this->advancedSettingsService->save($payload);
        }
    }

    public function getMtlsCaCertificate(): string
    {
        return $this->advancedSettingsService->mtlsCaCertificate();
    }

    public function isMtlsValidationEnabled(): bool
    {
        return false;
    }

    public function isMtlsFeatureAvailable(): bool
    {
        return self::MTLS_FEATURE_ENABLED;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function saveMtlsSettings(array $settings): void
    {
        throw new \RuntimeException('Mutual TLS (mTLS) is not available yet.', 1712100200);
    }

    /**
     * @return array<string, mixed>
     */
    public function allSecuritySettings(): array
    {
        return [
            'enforcePkce' => $this->isPkceEnforced() ? 1 : 0,
            'oauthDefaultScopes' => $this->getDefaultScopes(),
            'mtlsCaCertificate' => $this->getMtlsCaCertificate(),
            'mtlsValidationEnabled' => $this->isMtlsValidationEnabled() ? 1 : 0,
            'ipAllowlist' => $this->listIpAllowlist(),
        ];
    }
}
