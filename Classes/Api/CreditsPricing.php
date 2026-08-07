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
 * Billing parameters returned by T3Planet Credits API (`pricing` object).
 *
 * @api
 */
final readonly class CreditsPricing
{
    public const DEFAULT_MODEL = 'metered';

    public const DEFAULT_TOKENS_PER_CREDIT = 1000;

    /**
     * @param list<array{feature_key: string, typical_credits: float, basis: string, metered: bool}> $rateCard
     */
    public function __construct(
        public string $model = self::DEFAULT_MODEL,
        public int $tokensPerCredit = self::DEFAULT_TOKENS_PER_CREDIT,
        public int $creditUnitScale = AiCreditUnits::SCALE,
        public int $minChargeUnits = AiCreditUnits::MIN_CHARGE_UNITS,
        public float $inputTokenRate = 1.0,
        public float $outputTokenRate = 1.0,
        public array $rateCard = [],
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
            rateCard: self::parseRateCard($pricing['rate_card'] ?? null),
        );
    }

    public static function default(): self
    {
        return new self();
    }

    public function typicalCreditsFor(string $featureKey): ?float
    {
        foreach ($this->rateCard as $entry) {
            if (($entry['feature_key'] ?? '') === $featureKey) {
                return (float) ($entry['typical_credits'] ?? 0.0);
            }
        }

        return null;
    }

    /**
     * User-facing footnote for credits-mode UI (no token metrics).
     */
    public function footnote(): string
    {
        $minCredit = AiCreditUnits::formatCredits(
            AiCreditUnits::unitsToCredits($this->minChargeUnits, $this->creditUnitScale),
            3,
        );

        return sprintf(
            'Minimum %s credit per successful call. Use Estimate before submit for an approximate cost.',
            $minCredit,
        );
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
        $data = [
            'model' => $this->model,
            'credit_unit_scale' => $this->creditUnitScale,
            'min_charge_units' => $this->minChargeUnits,
            'footnote' => $this->footnote(),
        ];
        if ($this->rateCard !== []) {
            $data['rate_card'] = $this->rateCard;
        } else {
            $data['tokens_per_credit'] = $this->tokensPerCredit;
            $data['input_token_rate'] = $this->inputTokenRate;
            $data['output_token_rate'] = $this->outputTokenRate;
        }

        return $data;
    }

    /**
     * @return list<array{feature_key: string, typical_credits: float, basis: string, metered: bool}>
     */
    private static function parseRateCard(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $featureKey = trim((string) ($item['feature_key'] ?? ''));
            if ($featureKey === '') {
                continue;
            }
            $entries[] = [
                'feature_key' => $featureKey,
                'typical_credits' => (float) ($item['typical_credits'] ?? 0.0),
                'basis' => (string) ($item['basis'] ?? ''),
                'metered' => (bool) ($item['metered'] ?? true),
            ];
        }

        return $entries;
    }
}
