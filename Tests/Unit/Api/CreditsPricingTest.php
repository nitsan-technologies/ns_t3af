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
        self::assertStringContainsString('0.001', $pricing->footnote());
        self::assertStringNotContainsString('billable tokens', $pricing->footnote());
    }

    public function testFromArrayParsesMeteredRateCard(): void
    {
        $pricing = CreditsPricing::fromArray([
            'pricing' => [
                'model' => 'metered',
                'credit_unit_scale' => 1000,
                'min_charge_units' => 1,
                'rate_card' => [
                    [
                        'feature_key' => 'content_generate',
                        'typical_credits' => 1.244,
                        'basis' => '4000 in + 1200 out tokens',
                        'metered' => true,
                    ],
                ],
            ],
        ]);

        self::assertSame('metered', $pricing->model);
        self::assertCount(1, $pricing->rateCard);
        self::assertSame(1.244, $pricing->typicalCreditsFor('content_generate'));
        self::assertNull($pricing->typicalCreditsFor('missing'));
    }

    public function testCreditsUsageIgnoresRemovedTokenFields(): void
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
                'feature_key' => 'content_generate',
                'model' => 'gpt-4o',
            ],
            'uuid-1',
            [
                'tokens_input' => 2000,
                'tokens_output' => 500,
                'tokens_total' => 2500,
                'pricing' => ['model' => 'metered', 'credit_unit_scale' => 1000],
            ],
        );

        self::assertSame(3000, $usage->chargedUnits);
        self::assertSame(3.0, $usage->charged);
        self::assertSame(0, $usage->tokensTotal);
        self::assertSame(0, $usage->tokensInput);
        self::assertSame(0, $usage->tokensOutput);
        self::assertSame('gpt-4o', $usage->model);
        self::assertNotNull($usage->pricing);
    }

    public function testCreditsEstimateLabelAndCanonicalFeatureKey(): void
    {
        $estimate = CreditsEstimate::fromApiPayload([
            'feature_key' => 'content_generate',
            'canonical_feature_key' => 'content_generate',
            'estimated_credit_units' => 2000,
            'estimated_credits' => 2.0,
            'pricing' => ['model' => 'metered', 'credit_unit_scale' => 1000],
        ]);

        self::assertStringContainsString('2', $estimate->label());
        self::assertStringContainsString('credits', $estimate->label());
        self::assertSame('content_generate', $estimate->canonicalFeatureKey);
        self::assertSame(0, $estimate->billableTokens);
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
