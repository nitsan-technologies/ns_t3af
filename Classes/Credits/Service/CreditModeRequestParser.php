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

/**
 * @internal
 */
final class CreditModeRequestParser
{
    /**
     * @param array<string, mixed> $body
     */
    public static function claimCodeFromBody(array $body): ?string
    {
        $raw = $body['claimCode'] ?? $body['claim_code'] ?? '';
        if (is_array($raw) || !is_scalar($raw)) {
            return null;
        }

        return trim((string) $raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function enabledFromBody(array $body): ?bool
    {
        if (!array_key_exists('enabled', $body)) {
            return null;
        }

        return self::parseBoolean($body['enabled']);
    }

    public static function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1 ? true : ((int) $value === 0 ? false : null);
        }
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }
}
