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

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;

/**
 * Delay math for Credits API rate_limited / upstream 429 retries.
 *
 * @internal
 */
final class CreditsRateLimitBackoff
{
    public const MAX_ATTEMPTS = 4;

    /**
     * Seconds to wait before the next attempt (1-based attempt of the failed call).
     *
     * Prefer server {@code retry_after} when present; otherwise exponential backoff
     * with full jitter: random in [0, min(cap, base * 2^(attempt-1))].
     */
    public static function delaySeconds(int $failedAttempt, int $retryAfterSeconds = 0, ?float $randomUnit = null): float
    {
        $failedAttempt = max(1, $failedAttempt);
        if ($retryAfterSeconds > 0) {
            return (float) min(60, $retryAfterSeconds);
        }

        $base = 0.5;
        $cap = 8.0;
        $exp = $base * (2 ** ($failedAttempt - 1));
        $ceiling = min($cap, $exp);
        $unit = $randomUnit ?? (mt_rand() / mt_getrandmax());

        return max(0.05, $ceiling * max(0.0, min(1.0, $unit)));
    }

    public static function isRateLimited(string $errorCode, int $httpStatus = 0): bool
    {
        return $errorCode === CreditsApiErrorCodes::RATE_LIMITED
            || $errorCode === CreditsApiErrorCodes::CONCURRENCY_LIMIT
            || $httpStatus === 429;
    }
}
