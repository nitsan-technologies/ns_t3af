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

namespace NITSAN\NsT3AF\Credits\Http;

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;

/**
 * Single place that turns a Credits API error body into an exception.
 *
 * Handles the flat envelope ({status:false, error_code, …}), the OpenAI envelope
 * ({error:{message, type, code, param, …}}) and SSE usage payloads, and keeps the
 * diagnostic extras (param, model, upstream_error_code, …) for translated messages
 * and retry decisions.
 *
 * @internal
 */
final class CreditsApiErrorParser
{
    /** Extras copied from the top level or from a nested `error` object. */
    private const EXTRA_KEYS = [
        'retry_after',
        'topup_url',
        'feature_key',
        'credits',
        'request_uuid',
        'cost',
        'cost_units',
        'credits_needed',
        'credits_needed_units',
        'pricing',
        'param',
        'model',
        'upstream_error_code',
        'upstream_status',
        'upstream_body_snippet',
        'hint',
        'backend',
    ];

    private const DETAIL_KEYS = ['upstream_message', 'upstream_error', 'upstream_body_snippet', 'detail'];

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $additionalExtra Extra fields merged in after parsing (e.g. SSE usage fields).
     */
    public static function toException(
        array $decoded,
        int $httpStatus = 0,
        ?\Throwable $previous = null,
        array $additionalExtra = [],
    ): CreditsApiException {
        $nested = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
        $errorField = $decoded['error'] ?? null;

        if ($nested !== null) {
            $code = (string) ($nested['code'] ?? $decoded['error_code'] ?? CreditsApiErrorCodes::API_ERROR);
            $message = (string) ($nested['message'] ?? $decoded['message'] ?? '');
        } else {
            $code = is_string($errorField) && $errorField !== ''
                ? $errorField
                : (string) ($decoded['error_code'] ?? $decoded['code'] ?? CreditsApiErrorCodes::API_ERROR);
            $message = (string) ($decoded['message'] ?? '');
        }
        if ($code === '') {
            $code = CreditsApiErrorCodes::API_ERROR;
        }

        $extra = [];
        foreach (self::EXTRA_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $extra[$key] = $decoded[$key];
            } elseif ($nested !== null && array_key_exists($key, $nested)) {
                $extra[$key] = $nested[$key];
            }
        }
        // OpenAI envelope carries `param` inside `error`; keep it when set to a non-null value only.
        if (array_key_exists('param', $extra) && $extra['param'] === null) {
            unset($extra['param']);
        }

        // Server remaps this already; keep the client robust against older servers.
        if (
            $code === CreditsApiErrorCodes::UPSTREAM_AI_ERROR
            && ($extra['upstream_error_code'] ?? null) === CreditsApiErrorCodes::CONTEXT_LENGTH_EXCEEDED
        ) {
            $code = CreditsApiErrorCodes::CONTEXT_LENGTH_EXCEEDED;
        }

        foreach (self::DETAIL_KEYS as $detailKey) {
            $detail = trim((string) ($decoded[$detailKey] ?? ($nested[$detailKey] ?? '')));
            if ($detail !== '' && !str_contains($message, $detail)) {
                $message = $message !== '' && $message !== $code
                    ? $message . ' — ' . $detail
                    : $detail;
            }
        }
        if ($message === '') {
            $message = $code;
        }

        $topupUrl = (string) ($nested['topup_url'] ?? $decoded['topup_url'] ?? '');
        if ($topupUrl !== '' && !isset($extra['topup_url'])) {
            $extra['topup_url'] = $topupUrl;
        }

        foreach ($additionalExtra as $key => $value) {
            $extra[$key] ??= $value;
        }

        $status = $httpStatus > 0 ? $httpStatus : CreditsApiErrorCodes::httpStatus($code);

        if ($status === 402 || $code === CreditsApiErrorCodes::INSUFFICIENT_CREDITS) {
            return new InsufficientCreditsException(
                $message !== $code ? $message : 'Insufficient credits',
                $topupUrl,
                $extra,
                $previous,
            );
        }

        return new CreditsApiException($code, $status, $message, $extra, $previous);
    }
}
