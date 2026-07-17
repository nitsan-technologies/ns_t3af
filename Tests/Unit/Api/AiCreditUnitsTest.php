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

namespace NITSAN\NsT3AF\Tests\Unit\Api;

use NITSAN\NsT3AF\Api\AiCreditUnits;
use NITSAN\NsT3AF\Api\CreditsEstimate;
use NITSAN\NsT3AF\Api\CreditsUsage;
use PHPUnit\Framework\TestCase;

final class AiCreditUnitsTest extends TestCase
{
    public function testParseBalanceWithDecimalCreditsAndUnits(): void
    {
        $buckets = AiCreditUnits::parseBalanceBuckets([
            'free_credits' => 95.5,
            'free_units' => 95500,
            'paid_credits' => 0.0,
            'paid_units' => 0,
            'available_credit_units' => 95500,
            'available_credits' => 95.5,
            'pricing' => ['credit_unit_scale' => 1000],
        ]);

        self::assertSame(95500, $buckets['availableUnits']);
        self::assertSame(95.5, $buckets['availableCredits']);
        self::assertSame(95500, $buckets['freeUnits']);
        self::assertSame(95.5, $buckets['freeCredits']);
    }

    public function testParseChargeSuccessCost(): void
    {
        $cost = AiCreditUnits::parseCost(
            [
                'cost_units' => 31,
                'cost' => 0.031,
                'pricing' => ['credit_unit_scale' => 1000],
            ],
            [],
        );

        self::assertSame(31, $cost['units']);
        self::assertSame(0.031, $cost['credits']);
    }

    public function testCreditsUsageFromFractionalCharge(): void
    {
        $usage = CreditsUsage::fromApiPayload(
            [
                'free' => 95.469,
                'free_units' => 95469,
                'available_credits' => 95.469,
                'available_credit_units' => 95469,
            ],
            [
                'amount_units' => 31,
                'amount' => 0.031,
                'bucket' => 'free',
                'feature_key' => 'seo_meta',
            ],
            'uuid-1',
            [
                'cost_units' => 31,
                'cost' => 0.031,
                'tokens_input' => 19,
                'tokens_output' => 12,
                'pricing' => [
                    'credit_unit_scale' => 1000,
                    'tokens_per_credit' => 1000,
                    'min_charge_units' => 1,
                ],
            ],
        );

        self::assertSame(31, $usage->chargedUnits);
        self::assertSame(0.031, $usage->charged);
    }

    public function testCreditsEstimateFractional(): void
    {
        $estimate = CreditsEstimate::fromApiPayload([
            'feature_key' => 'seo_meta',
            'estimated_credit_units' => 31,
            'estimated_credits' => 0.031,
            'billable_tokens' => 31,
            'pricing' => [
                'tokens_per_credit' => 1000,
                'credit_unit_scale' => 1000,
                'min_charge_units' => 1,
            ],
        ]);

        self::assertSame(31, $estimate->estimatedCreditUnits);
        self::assertSame(0.031, $estimate->estimatedCredits);
        self::assertSame(31, $estimate->billableTokens);
        self::assertStringContainsString('0.031', $estimate->label());
    }

    public function testCanAffordUnits(): void
    {
        self::assertTrue(AiCreditUnits::canAfford(31, 31));
        self::assertFalse(AiCreditUnits::canAfford(30, 31));
    }

    public function testLegacyWholeCreditCostWithoutScale(): void
    {
        $cost = AiCreditUnits::parseCost(
            ['cost' => 2],
            ['amount' => 2],
        );

        self::assertSame(2000, $cost['units']);
        self::assertSame(2.0, $cost['credits']);
    }

    public function testParseReceiptLegacyCostColumn(): void
    {
        $parsed = AiCreditUnits::parseReceiptCost(['cost' => 2, 'cost_units' => 0]);

        self::assertSame(2000, $parsed['units']);
        self::assertSame(2.0, $parsed['credits']);
    }

    public function testFormatCreditsShowsFractions(): void
    {
        self::assertSame('0.031', AiCreditUnits::formatCredits(0.031));
    }
}
