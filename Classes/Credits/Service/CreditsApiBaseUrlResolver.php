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

use NITSAN\NsT3AF\Credits\CreditsConstants;

/**
 * Resolves the T3Planet Credits API base URL.
 *
 * Precedence:
 * 1. {@see self::ENVIRONMENT_VARIABLE} (staging, DDEV, or any custom host)
 * 2. {@see CreditsConstants::DEFAULT_API_BASE_URL} (production)
 *
 * @internal
 */
final class CreditsApiBaseUrlResolver
{
    public const ENVIRONMENT_VARIABLE = 'T3PLANET_CREDITS_API_BASE_URL';

    public function resolve(): string
    {
        $fromEnvironment = $this->resolveFromEnvironmentVariable();
        if ($fromEnvironment !== '') {
            return $fromEnvironment;
        }

        return $this->normalize(CreditsConstants::DEFAULT_API_BASE_URL);
    }

    /**
     * @return list<string>
     */
    public function knownBuiltInBaseUrls(): array
    {
        return [
            '',
            CreditsConstants::DEFAULT_API_BASE_URL,
            CreditsConstants::LOCAL_DDEV_API_BASE_URL,
        ];
    }

    public function isKnownBuiltInUrl(string $url): bool
    {
        $normalized = $this->normalize($url);
        foreach ($this->knownBuiltInBaseUrls() as $builtIn) {
            if ($this->normalize($builtIn) === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function normalize(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    private function resolveFromEnvironmentVariable(): string
    {
        $raw = getenv(self::ENVIRONMENT_VARIABLE);
        if ($raw === false) {
            return '';
        }

        $trimmed = trim((string) $raw);

        return $trimmed !== '' ? $this->normalize($trimmed) : '';
    }
}
