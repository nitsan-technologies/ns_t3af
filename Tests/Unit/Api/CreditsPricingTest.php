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

use NITSAN\NsT3AF\Api\CreditsEstimate;
use NITSAN\NsT3AF\Api\CreditsPricing;
use NITSAN\NsT3AF\Api\CreditsUsage;
use PHPUnit\Framework\TestCase;

final class CreditsPricingTest extends TestCase
{
    public function testFromArrayUsesNestedPricing(): void
    {
        $pricing = CreditsPricing::fromArray([
            'pricing' => [
                'model' => 'token',
                'tokens_per_credit' => 2000,
                'credit_unit_scale' => 1000,
                'min_charge_units' => 1,
                'input_token_rate' => 1.5,
                'output_token_rate' => 2.0,
            ],
        ]);

        self::assertSame(2000, $pricing->tokensPerCredit);
        self::assertSame(1000, $pricing->creditUnitScale);
        self::assertSame(1.5, $pricing->inputTokenRate);
        self::assertStringContainsString('2,000', $pricing->footnote());
        self::assertStringContainsString('0.001', $pricing->footnote());
    }

    public function testCreditsUsageMapsTokenFields(): void
    {
        $usage = CreditsUsage::fromApiPayload(
            [
                'free' => 10.0,
                'paid' => 0.0,
                'plan_used' => 1.0,
                'plan_total' => 100.0,
                'plan_name' => 'starter',
                'expires_at' => 0,
            ],
            [
                'amount_units' => 3000,
                'amount' => 3.0,
                'bucket' => 'free',
                'feature_key' => 'seo_meta',
                'tokens_total' => 2500,
                'model' => 'gpt-4o',
            ],
            'uuid-1',
            [
                'tokens_input' => 2000,
                'tokens_output' => 500,
                'tokens_total' => 2500,
                'pricing' => ['tokens_per_credit' => 1000, 'credit_unit_scale' => 1000],
            ],
        );

        self::assertSame(3000, $usage->chargedUnits);
        self::assertSame(3.0, $usage->charged);
        self::assertSame(2500, $usage->tokensTotal);
        self::assertSame(2000, $usage->tokensInput);
        self::assertSame(500, $usage->tokensOutput);
        self::assertSame('gpt-4o', $usage->model);
        self::assertNotNull($usage->pricing);
    }

    public function testCreditsEstimateLabel(): void
    {
        $estimate = CreditsEstimate::fromApiPayload([
            'feature_key' => 'seo_meta',
            'estimated_credit_units' => 2000,
            'estimated_credits' => 2.0,
            'pricing' => ['tokens_per_credit' => 1000, 'credit_unit_scale' => 1000],
        ]);

        self::assertStringContainsString('2', $estimate->label());
        self::assertStringContainsString('credits', $estimate->label());
    }

    public function testFormatEstimateUsesMinimumChargeWhenZero(): void
    {
        $pricing = CreditsPricing::default();
        $label = $pricing->formatEstimate(0, 0.0);

        self::assertStringContainsString('0.001', $label);
    }

    public function testToArrayIncludesFractionalPricingFields(): void
    {
        $array = CreditsPricing::default()->toArray();

        self::assertArrayHasKey('credit_unit_scale', $array);
        self::assertArrayHasKey('min_charge_units', $array);
        self::assertArrayNotHasKey('minimum_credits_per_request', $array);
    }
}
