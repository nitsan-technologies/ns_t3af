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

namespace NITSAN\NsT3AF\Service;

/**
 * SSRF guard for server-side fetches of user-supplied URLs.
 *
 * Accepts only http/https URLs whose host resolves to a public
 * (non-private, non-reserved) IP address. Rejects direct IP literals in
 * private/reserved ranges and hostnames that fail DNS resolution, so
 * cloud metadata endpoints (169.254.169.254), loopback, and RFC 1918
 * ranges are unreachable.
 *
 * @internal
 */
class PublicUrlValidator
{
    public function isPublicUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        // IPv6 literals arrive bracketed from parse_url().
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        $resolvedIp = gethostbyname($host);
        if ($resolvedIp === $host) {
            // gethostbyname() returns the input unchanged on resolution failure.
            return false;
        }

        return $this->isPublicIp($resolvedIp);
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        // CGNAT / cloud-metadata-adjacent ranges not covered by NO_PRIV_RANGE.
        if ($this->ipv4InCidr($ip, '100.64.0.0/10')
            || $this->ipv4InCidr($ip, '192.0.0.0/24')
            || $this->ipv4InCidr($ip, '198.18.0.0/15')
        ) {
            return false;
        }

        return true;
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskInt = (int) $mask;
        $maskLong = -1 << (32 - $maskInt);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
