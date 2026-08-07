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
 * Ledger direction for local credit receipts / server History rows.
 *
 * @internal
 */
final class CreditsReceiptEntryType
{
    public const ALL = 'all';

    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    /**
     * @return list<string>
     */
    public static function filterValues(): array
    {
        return [self::ALL, self::DEBIT, self::CREDIT];
    }

    /**
     * @return list<string>
     */
    public static function storedValues(): array
    {
        return [self::DEBIT, self::CREDIT];
    }

    public static function normalize(mixed $value, string $default = self::DEBIT): string
    {
        $raw = strtolower(trim((string) $value));
        if (in_array($raw, self::storedValues(), true)) {
            return $raw;
        }

        return in_array($default, self::storedValues(), true) ? $default : self::DEBIT;
    }

    public static function normalizeFilter(mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '' || $raw === self::ALL) {
            return self::ALL;
        }

        return in_array($raw, self::storedValues(), true) ? $raw : self::ALL;
    }
}
