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
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;

/**
 * Which Credits API failures may be retried (rate limits and idempotency conflicts keep their
 * own handling in the executors).
 *
 * The server passes provider codes through in `upstream_error_code` without a closed list, so
 * the decision uses our own codes plus `upstream_status` only — never unknown provider strings.
 *
 * @internal
 */
final class CreditsRetryPolicy
{
    /** Retrying cannot help: fix the request, model, plan or balance instead. */
    private const NEVER_RETRY = [
        CreditsApiErrorCodes::CONTEXT_LENGTH_EXCEEDED,
        CreditsApiErrorCodes::TOOLS_UNSUPPORTED,
        CreditsApiErrorCodes::MODEL_UNKNOWN,
        CreditsApiErrorCodes::MODEL_NOT_ALLOWED,
        CreditsApiErrorCodes::REQUEST_TOO_LARGE,
        CreditsApiErrorCodes::UNSUPPORTED_CONTENT,
        CreditsApiErrorCodes::INSUFFICIENT_CREDITS,
        CreditsApiErrorCodes::PLAN_EXPIRED,
        CreditsApiErrorCodes::DAILY_CAP_EXCEEDED,
        CreditsApiErrorCodes::REQUIRED_FIELD_MISSING,
        CreditsApiErrorCodes::FEATURE_UNKNOWN,
        CreditsApiErrorCodes::BATCH_INVALID,
        CreditsApiErrorCodes::CONTENT_REMOVED,
    ];

    /** Retry the same call at most this many times for transient upstream failures. */
    public const MAX_UPSTREAM_RETRIES = 1;

    public static function isNeverRetry(CreditsApiException $exception): bool
    {
        return in_array($exception->errorCode, self::NEVER_RETRY, true);
    }

    /**
     * Timeout, or an upstream failure the provider reported as 5xx / 408. Retry once with a fresh request_uuid.
     */
    public static function isTransientUpstream(CreditsApiException $exception): bool
    {
        if (self::isNeverRetry($exception)) {
            return false;
        }

        if ($exception->errorCode === CreditsApiErrorCodes::UPSTREAM_AI_TIMEOUT) {
            return true;
        }

        if ($exception->errorCode !== CreditsApiErrorCodes::UPSTREAM_AI_ERROR) {
            return false;
        }

        $status = $exception->extra['upstream_status'] ?? null;
        if (!is_int($status) && !(is_string($status) && ctype_digit($status))) {
            return false;
        }
        $status = (int) $status;

        return $status === 408 || ($status >= 500 && $status <= 599);
    }

    public static function shouldRetryUpstream(CreditsApiException $exception, int $upstreamRetriesDone): bool
    {
        return $upstreamRetriesDone < self::MAX_UPSTREAM_RETRIES && self::isTransientUpstream($exception);
    }
}
