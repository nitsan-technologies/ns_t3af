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
 * Allowlist for product checkout URLs loaded in the backend checkout iframe.
 *
 * @internal
 */
final class CreditsCheckoutUrlValidator
{
    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'payments.pabbly.com',
        'pabbly.com',
        'pabbly.t3planet.de',
        't3planet.shop',
        'www.t3planet.shop',
    ];

    /** @var list<string> */
    private const ALLOWED_HOST_SUFFIXES = [
        '.t3planet.de',
        '.t3planet.shop',
        '.t3planet.com',
        '.pabbly.com',
    ];

    public function isAllowed(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return true;
        }

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.')) {
                return true;
            }
        }

        return false;
    }
}
