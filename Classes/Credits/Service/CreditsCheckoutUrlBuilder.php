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

/**
 * Normalizes product checkout URLs from Products.php.
 *
 * v1.1: server returns fully built Pabbly `checkout_url` — use as-is, then replace
 * Pabbly custom fields `cf_credittoken*` with the install Bearer token (not license key).
 * Legacy templates may contain `{license_key}`, `{token}`, `{domain}`, `{return}` placeholders.
 *
 * @internal
 */
final class CreditsCheckoutUrlBuilder
{
    public function __construct(
        private readonly RuntimeSettingsService $runtimeSettings,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly CreditsReturnUrlBuilder $returnUrlBuilder,
    ) {}

    public function normalize(string $checkoutUrl, string $returnUrl): string
    {
        if ($checkoutUrl === '') {
            return '';
        }

        $returnUrl = $this->returnUrlBuilder->normalize($returnUrl);

        $url = str_contains($checkoutUrl, '{')
            ? $this->substitutePlaceholders($checkoutUrl, $returnUrl)
            : $checkoutUrl;

        return $this->applyCheckoutQueryParams($url, $returnUrl);
    }

    private function substitutePlaceholders(string $checkoutUrl, string $returnUrl): string
    {
        $licenseKey = $this->primaryLicenseKey();
        $domain = $this->domainResolver->resolve();
        $token = trim($this->runtimeSettings->getTokenPlain() ?? '');

        return str_replace(
            ['{license_key}', '{token}', '{domain}', '{return}'],
            [
                rawurlencode($licenseKey),
                rawurlencode($token),
                rawurlencode($domain),
                rawurlencode($returnUrl),
            ],
            $checkoutUrl,
        );
    }

    /**
     * Pabbly checkout custom fields:
     * - cf_credittoken* → install Bearer token
     * - cf_redirectto*  → absolute backend return URL (scheme + host, no route token)
     */
    private function applyCheckoutQueryParams(string $checkoutUrl, string $returnUrl): string
    {
        $token = trim($this->runtimeSettings->getTokenPlain() ?? '');

        $parts = parse_url($checkoutUrl);
        if (!is_array($parts)) {
            return $checkoutUrl;
        }

        $queryString = (string) ($parts['query'] ?? '');
        if ($queryString === '') {
            return $checkoutUrl;
        }

        parse_str($queryString, $query);
        $changed = false;
        foreach (array_keys($query) as $name) {
            $lower = strtolower((string) $name);
            if ($token !== '' && str_starts_with($lower, 'cf_credittoken')) {
                $query[$name] = $token;
                $changed = true;
            }
            if ($returnUrl !== '' && str_starts_with($lower, 'cf_redirectto')) {
                $query[$name] = $returnUrl;
                $changed = true;
            }
        }

        if (!$changed) {
            return $checkoutUrl;
        }

        $parts['query'] = http_build_query($query);

        return $this->composeUrl($parts);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function composeUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user = (string) ($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }

    private function primaryLicenseKey(): string
    {
        $keys = trim($this->runtimeSettings->getLicenseKeys());
        if ($keys === '') {
            return '';
        }

        $parts = array_map(static fn(string $part): string => trim($part), explode(',', $keys));

        return $parts[0] ?? '';
    }
}
