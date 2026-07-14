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

namespace NITSAN\NsT3AF\Credits;

/**
 * T3Planet Credits API error codes (mirrors server AiErrorCodes).
 *
 * @internal
 */
final class CreditsApiErrorCodes
{
    public const TOKEN_MISSING = 'token_missing';
    public const TOKEN_INVALID = 'token_invalid';
    public const LICENSE_INVALID = 'license_invalid';
    public const LICENSE_EXPIRED = 'license_expired';
    public const LICENSE_SUSPENDED = 'license_suspended';
    public const DOMAIN_MISMATCH = 'domain_mismatch';
    public const INSUFFICIENT_CREDITS = 'insufficient_credits';
    public const PLAN_EXPIRED = 'plan_expired';
    public const RATE_LIMITED = 'rate_limited';
    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    public const STREAM_IN_PROGRESS = 'stream_in_progress';
    public const FEATURE_UNKNOWN = 'feature_unknown';
    public const REQUEST_NOT_FOUND = 'request_not_found';
    public const ADMIN_UNAUTHORIZED = 'admin_unauthorized';
    public const UPSTREAM_AI_ERROR = 'upstream_ai_error';
    public const UPSTREAM_AI_TIMEOUT = 'upstream_ai_timeout';
    public const REQUIRED_FIELD_MISSING = 'required_field_missing';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const INTERNAL_ERROR = 'internal_error';

    /** Client-side codes (not emitted by remote API). */
    public const NETWORK_ERROR = 'network_error';
    public const INVALID_RESPONSE = 'invalid_response';
    public const LICENSE_KEYS_MISSING = 'license_keys_missing';
    public const NO_LICENSES = 'no_licenses';
    public const FEATURE_KEY_REQUIRED = 'feature_key_required';
    public const API_ERROR = 'api_error';

    public static function httpStatus(string $code, int $responseStatus = 0): int
    {
        if ($responseStatus >= 400) {
            return $responseStatus;
        }

        return match ($code) {
            self::TOKEN_MISSING, self::TOKEN_INVALID, self::ADMIN_UNAUTHORIZED => 401,
            self::LICENSE_INVALID, self::LICENSE_EXPIRED, self::LICENSE_SUSPENDED, self::DOMAIN_MISMATCH => 403,
            self::INSUFFICIENT_CREDITS, self::PLAN_EXPIRED => 402,
            self::RATE_LIMITED => 429,
            self::IDEMPOTENCY_CONFLICT => 409,
            self::FEATURE_UNKNOWN, self::REQUIRED_FIELD_MISSING, self::REQUEST_NOT_FOUND => 422,
            self::UPSTREAM_AI_ERROR, self::UPSTREAM_AI_TIMEOUT => 502,
            self::METHOD_NOT_ALLOWED => 405,
            self::LICENSE_KEYS_MISSING, self::NO_LICENSES, self::FEATURE_KEY_REQUIRED => 400,
            self::NETWORK_ERROR, self::INVALID_RESPONSE, self::API_ERROR => 502,
            default => 500,
        };
    }
}
