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
 * Formats an estimated cost with the provider currency. No exchange conversion.
 *
 * @internal
 */
final class MoneyFormatter
{
    /** @var array<string, string> */
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    public function format(float $amount, string $currency = 'USD'): string
    {
        $code = $this->normalize($currency);
        $number = $this->formatAmount($amount);
        $symbol = self::SYMBOLS[$code] ?? null;
        if ($symbol === null) {
            return $number . ' ' . $code;
        }

        return $symbol . $number;
    }

    public function formatAmount(float $amount): string
    {
        if ($amount > 0.0 && $amount < 0.01) {
            return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');
        }

        return number_format($amount, 2);
    }

    public function normalize(string $currency): string
    {
        $normalized = strtoupper(trim($currency));
        if ($normalized === '' || strlen($normalized) !== 3 || !ctype_alpha($normalized)) {
            return 'USD';
        }

        return $normalized;
    }

    /**
     * Currency shared by rows that have spend. Null when those rows use more than one code.
     * An empty map with spend falls back to USD. No spend uses the single mapped code, or USD.
     *
     * @param array<string, string> $currencyByProvider
     * @param array<int|string, array<string, mixed>> $rows
     */
    public function singleCurrency(array $currencyByProvider, array $rows): ?string
    {
        $spent = [];
        foreach ($rows as $row) {
            if ((float) ($row['cost'] ?? 0.0) <= 0.0) {
                continue;
            }
            $provider = (string) ($row['provider'] ?? '');
            $spent[$this->normalize((string) ($currencyByProvider[$provider] ?? 'USD'))] = true;
        }
        if ($spent !== []) {
            return count($spent) === 1 ? (string) array_key_first($spent) : null;
        }

        $mapped = [];
        foreach ($currencyByProvider as $code) {
            $mapped[$this->normalize((string) $code)] = true;
        }
        if (count($mapped) === 1) {
            return (string) array_key_first($mapped);
        }

        return $mapped === [] ? 'USD' : null;
    }
}
