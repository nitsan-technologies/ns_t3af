<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace NITSAN\NsT3AF\Credits\Http;

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;

/**
 * @internal
 */
final class CreditsApiErrorCodeNormalizer
{
    public static function normalize(mixed $candidate, string $fallback = CreditsApiErrorCodes::API_ERROR): string
    {
        $code = trim((string) $candidate);
        if ($code === '') {
            return $fallback;
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/', $code) === 1) {
            return $code;
        }

        return $fallback;
    }
}
