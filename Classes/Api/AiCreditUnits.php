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

namespace NITSAN\NsT3AF\Api;

/**
 * Fixed-point T3Planet credit units (1 credit = 1000 units) — parse, compare, format.
 *
 * @api
 */
final class AiCreditUnits
{
    public const SCALE = 1000;

    public const MIN_CHARGE_UNITS = 1;

    /**
     * @param array<string, mixed>|null $pricing
     */
    public static function isFractionalApi(?array $pricing): bool
    {
        if ($pricing === null || $pricing === []) {
            return false;
        }

        $block = isset($pricing['pricing']) && is_array($pricing['pricing'])
            ? $pricing['pricing']
            : $pricing;

        return array_key_exists('credit_unit_scale', $block);
    }

    /**
     * @param array<string, mixed> $pricing
     */
    public static function scaleFromPricing(array $pricing): int
    {
        $block = isset($pricing['pricing']) && is_array($pricing['pricing'])
            ? $pricing['pricing']
            : $pricing;
        $scale = (int) ($block['credit_unit_scale'] ?? self::SCALE);

        return $scale > 0 ? $scale : self::SCALE;
    }

    public static function unitsToCredits(int $units, int $scale = self::SCALE): float
    {
        if ($scale <= 0) {
            $scale = self::SCALE;
        }

        return $units / $scale;
    }

    public static function creditsToUnits(float $credits, int $scale = self::SCALE): int
    {
        if ($scale <= 0) {
            $scale = self::SCALE;
        }

        return (int) round($credits * $scale);
    }

    /**
     * @param array<string, mixed>      $payload  Charge/Embed body or nested pricing context.
     * @param array<string, mixed>      $charged  Optional `charged` block.
     */
    public static function parseCost(array $payload, array $charged = []): array
    {
        $pricing = is_array($payload['pricing'] ?? null) ? $payload['pricing'] : [];
        $scale = self::scaleFromPricing($pricing);
        $fractional = self::isFractionalApi($pricing);

        if (isset($payload['cost_units'])) {
            $units = (int) $payload['cost_units'];

            return [
                'units' => max(0, $units),
                'credits' => (float) ($payload['cost'] ?? self::unitsToCredits($units, $scale)),
            ];
        }

        if (isset($charged['amount_units'])) {
            $units = (int) $charged['amount_units'];

            return [
                'units' => max(0, $units),
                'credits' => (float) ($charged['amount'] ?? self::unitsToCredits($units, $scale)),
            ];
        }

        $amount = $charged['amount'] ?? $payload['cost'] ?? 0;
        if (is_float($amount) || (is_string($amount) && str_contains((string) $amount, '.'))) {
            $credits = (float) $amount;

            return [
                'units' => self::creditsToUnits($credits, $scale),
                'credits' => $credits,
            ];
        }

        $intAmount = (int) $amount;
        if ($fractional) {
            $credits = (float) $intAmount / $scale;

            return ['units' => $intAmount, 'credits' => $credits];
        }

        // Legacy whole credits (pre–credit-units API).
        return [
            'units' => $intAmount * $scale,
            'credits' => (float) $intAmount,
        ];
    }

    /**
     * @param array<string, mixed> $credits Balance or Charge `credits` block (may be top-level balance).
     *
     * @return array{
     *   availableUnits: int,
     *   availableCredits: float,
     *   freeUnits: int,
     *   freeCredits: float,
     *   paidUnits: int,
     *   paidCredits: float,
     *   planUsedUnits: int,
     *   planUsedCredits: float,
     *   planTotalUnits: int,
     *   planTotalCredits: float,
     *   planRemainingUnits: int,
     *   planRemainingCredits: float
     * }
     */
    public static function parseBalanceBuckets(array $credits, int $scale = self::SCALE): array
    {
        if ($scale <= 0) {
            $scale = self::SCALE;
        }

        $free = self::parseBucket($credits, 'free', $scale);
        $paid = self::parseBucket($credits, 'paid', $scale);
        $planUsed = self::parseBucket($credits, 'plan_used', $scale, ['plan_credits_used']);
        $planTotal = self::parseBucket($credits, 'plan_total', $scale, ['plan_credits_total']);
        $planRemaining = self::parseBucket($credits, 'plan_remaining', $scale);

        if ($planRemaining['units'] === 0 && $planTotal['units'] > 0) {
            $planRemaining = [
                'units' => max(0, $planTotal['units'] - $planUsed['units']),
                'credits' => max(0.0, $planTotal['credits'] - $planUsed['credits']),
            ];
        }

        $availableUnits = (int) (
            $credits['available_credit_units']
            ?? $credits['total_remaining_units']
            ?? 0
        );
        $availableCredits = isset($credits['available_credits'])
            ? (float) $credits['available_credits']
            : (isset($credits['total_remaining']) ? (float) $credits['total_remaining'] : 0.0);

        if ($availableUnits <= 0 && $availableCredits <= 0.0) {
            $nested = is_array($credits['credits'] ?? null) ? $credits['credits'] : [];
            if ($nested !== []) {
                return self::parseBalanceBuckets($nested, $scale);
            }
            $explicitRemaining = (float) (
                $credits['remaining_credits']
                ?? 0
            );
            if ($explicitRemaining > 0) {
                $availableCredits = $explicitRemaining;
                $availableUnits = self::creditsToUnits($availableCredits, $scale);
            } else {
                $availableUnits = $planRemaining['units'] + $free['units'] + $paid['units'];
                $availableCredits = $planRemaining['credits'] + $free['credits'] + $paid['credits'];
            }
        } elseif ($availableUnits > 0 && $availableCredits <= 0.0) {
            $availableCredits = self::unitsToCredits($availableUnits, $scale);
        } elseif ($availableCredits > 0.0 && $availableUnits <= 0) {
            $availableUnits = self::creditsToUnits($availableCredits, $scale);
        }

        return [
            'availableUnits' => max(0, $availableUnits),
            'availableCredits' => max(0.0, $availableCredits),
            'freeUnits' => $free['units'],
            'freeCredits' => $free['credits'],
            'paidUnits' => $paid['units'],
            'paidCredits' => $paid['credits'],
            'planUsedUnits' => $planUsed['units'],
            'planUsedCredits' => $planUsed['credits'],
            'planTotalUnits' => $planTotal['units'],
            'planTotalCredits' => $planTotal['credits'],
            'planRemainingUnits' => $planRemaining['units'],
            'planRemainingCredits' => $planRemaining['credits'],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string>         $legacyKeys
     *
     * @return array{units: int, credits: float}
     */
    private static function parseBucket(array $source, string $base, int $scale, array $legacyKeys = []): array
    {
        $unitsKey = $base . '_units';
        if ($base === 'free') {
            $unitsKey = 'free_units';
        } elseif ($base === 'paid') {
            $unitsKey = 'paid_units';
        }

        if (isset($source[$unitsKey])) {
            $units = (int) $source[$unitsKey];
            $creditKey = $base === 'plan_used' ? 'plan_used' : ($base === 'plan_total' ? 'plan_total' : $base);
            if ($base === 'free') {
                $creditKey = 'free';
            } elseif ($base === 'paid') {
                $creditKey = 'paid';
            }
            $legacyCreditKeys = match ($base) {
                'free' => ['free_credits', 'free'],
                'paid' => ['paid_credits', 'paid'],
                'plan_used' => ['plan_used', 'plan_credits_used'],
                'plan_total' => ['plan_total', 'plan_credits_total'],
                'plan_remaining' => ['plan_remaining'],
                default => [$creditKey],
            };
            $credits = self::firstFloat($source, $legacyCreditKeys);

            return [
                'units' => max(0, $units),
                'credits' => $credits > 0.0 ? $credits : self::unitsToCredits($units, $scale),
            ];
        }

        $keys = array_merge(
            match ($base) {
                'free' => ['free_credits', 'free'],
                'paid' => ['paid_credits', 'paid'],
                'plan_used' => ['plan_used', 'plan_credits_used'],
                'plan_total' => ['plan_total', 'plan_credits_total'],
                'plan_remaining' => ['plan_remaining'],
                default => [$base],
            },
            $legacyKeys,
        );

        $raw = self::firstRaw($source, $keys);
        if ($raw === null) {
            return ['units' => 0, 'credits' => 0.0];
        }

        return self::rawToBucket($raw, $scale, self::isFractionalApi($source));
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string>         $keys
     */
    private static function firstFloat(array $source, array $keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if (is_float($value) || (is_string($value) && str_contains($value, '.'))) {
                return (float) $value;
            }
            if (is_int($value) || (is_string($value) && is_numeric($value))) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string>         $keys
     */
    private static function firstRaw(array $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                return $source[$key];
            }
        }

        return null;
    }

    /**
     * @return array{units: int, credits: float}
     */
    private static function rawToBucket(mixed $raw, int $scale, bool $fractionalApi): array
    {
        if (is_float($raw) || (is_string($raw) && str_contains((string) $raw, '.'))) {
            $credits = (float) $raw;

            return [
                'units' => self::creditsToUnits($credits, $scale),
                'credits' => $credits,
            ];
        }

        $int = (int) $raw;
        if ($fractionalApi) {
            return [
                'units' => $int,
                'credits' => self::unitsToCredits($int, $scale),
            ];
        }

        return [
            'units' => $int * $scale,
            'credits' => (float) $int,
        ];
    }

    public static function formatCredits(float $credits, int $maxDecimals = 3): string
    {
        if ($credits >= 1_000_000) {
            return rtrim(rtrim(number_format($credits / 1_000_000, 1, '.', ','), '0'), '.') . 'M';
        }
        if ($credits >= 1000) {
            return rtrim(rtrim(number_format($credits / 1000, 1, '.', ','), '0'), '.') . 'K';
        }
        if ($credits >= 100) {
            return number_format($credits, 0, '.', ',');
        }
        if ($credits >= 1) {
            $formatted = number_format($credits, min(2, $maxDecimals), '.', ',');
            return rtrim(rtrim($formatted, '0'), '.');
        }

        $formatted = number_format($credits, $maxDecimals, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    public static function canAfford(int $availableUnits, int $neededUnits): bool
    {
        $needed = max(self::MIN_CHARGE_UNITS, $neededUnits);

        return $availableUnits >= $needed;
    }

    /**
     * Resolve cost from a local receipt row (legacy int `cost` = whole credits).
     *
     * @param array<string, mixed> $receipt
     *
     * @return array{units: int, credits: float}
     */
    public static function parseReceiptCost(array $receipt): array
    {
        $costUnits = (int) ($receipt['cost_units'] ?? 0);
        if ($costUnits > 0) {
            $cost = $receipt['cost'] ?? null;
            if (is_numeric($cost)) {
                return ['units' => $costUnits, 'credits' => (float) $cost];
            }

            return ['units' => $costUnits, 'credits' => self::unitsToCredits($costUnits)];
        }

        $legacyCost = (int) ($receipt['cost'] ?? 0);
        if ($legacyCost <= 0) {
            return ['units' => 0, 'credits' => 0.0];
        }

        return [
            'units' => $legacyCost * self::SCALE,
            'credits' => (float) $legacyCost,
        ];
    }
}
