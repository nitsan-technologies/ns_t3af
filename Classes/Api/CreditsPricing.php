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
 * Token-based billing parameters returned by T3Planet Credits API (`pricing` object).
 *
 * @api
 */
final readonly class CreditsPricing
{
    public const DEFAULT_MODEL = 'token';

    public const DEFAULT_TOKENS_PER_CREDIT = 1000;

    public function __construct(
        public string $model = self::DEFAULT_MODEL,
        public int $tokensPerCredit = self::DEFAULT_TOKENS_PER_CREDIT,
        public int $creditUnitScale = AiCreditUnits::SCALE,
        public int $minChargeUnits = AiCreditUnits::MIN_CHARGE_UNITS,
        public float $inputTokenRate = 1.0,
        public float $outputTokenRate = 1.0,
    ) {}

    /**
     * @param array<string, mixed>|null $payload API response body or nested `pricing` array.
     */
    public static function fromArray(?array $payload): self
    {
        if ($payload === null || $payload === []) {
            return self::default();
        }

        $pricing = isset($payload['pricing']) && is_array($payload['pricing'])
            ? $payload['pricing']
            : $payload;

        $tokensPerCredit = (int) ($pricing['tokens_per_credit'] ?? self::DEFAULT_TOKENS_PER_CREDIT);
        if ($tokensPerCredit <= 0) {
            $tokensPerCredit = self::DEFAULT_TOKENS_PER_CREDIT;
        }

        $scale = (int) ($pricing['credit_unit_scale'] ?? AiCreditUnits::SCALE);
        if ($scale <= 0) {
            $scale = AiCreditUnits::SCALE;
        }

        $minChargeUnits = (int) ($pricing['min_charge_units'] ?? AiCreditUnits::MIN_CHARGE_UNITS);
        if ($minChargeUnits <= 0) {
            $minChargeUnits = AiCreditUnits::MIN_CHARGE_UNITS;
        }

        return new self(
            model: (string) ($pricing['model'] ?? self::DEFAULT_MODEL),
            tokensPerCredit: $tokensPerCredit,
            creditUnitScale: $scale,
            minChargeUnits: $minChargeUnits,
            inputTokenRate: (float) ($pricing['input_token_rate'] ?? 1.0),
            outputTokenRate: (float) ($pricing['output_token_rate'] ?? 1.0),
        );
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * User-facing footnote for token-based fractional billing.
     */
    public function footnote(): string
    {
        $tokens = number_format($this->tokensPerCredit, 0, '.', ',');
        $minCredit = AiCreditUnits::formatCredits(
            AiCreditUnits::unitsToCredits($this->minChargeUnits, $this->creditUnitScale),
            3,
        );
        $parts = [
            sprintf('1 credit ≈ %s billable tokens', $tokens),
            sprintf('min %s credit per successful call', $minCredit),
        ];
        if ($this->inputTokenRate !== 1.0 || $this->outputTokenRate !== 1.0) {
            $parts[] = sprintf(
                'input×%s / output×%s token weighting',
                rtrim(rtrim(number_format($this->inputTokenRate, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($this->outputTokenRate, 2, '.', ''), '0'), '.'),
            );
        }

        return implode(' · ', $parts);
    }

    /**
     * Short label for estimate UI: "≈ 0.031 credits".
     */
    public function formatEstimate(int $estimatedCreditUnits, float $estimatedCredits = 0.0): string
    {
        if ($estimatedCreditUnits > 0) {
            $credits = AiCreditUnits::unitsToCredits($estimatedCreditUnits, $this->creditUnitScale);
        } elseif ($estimatedCredits > 0.0) {
            $credits = $estimatedCredits;
        } else {
            $credits = AiCreditUnits::unitsToCredits($this->minChargeUnits, $this->creditUnitScale);
        }

        return '≈ ' . AiCreditUnits::formatCredits($credits) . ' credits';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'tokens_per_credit' => $this->tokensPerCredit,
            'credit_unit_scale' => $this->creditUnitScale,
            'min_charge_units' => $this->minChargeUnits,
            'input_token_rate' => $this->inputTokenRate,
            'output_token_rate' => $this->outputTokenRate,
            'footnote' => $this->footnote(),
        ];
    }
}
