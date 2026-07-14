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
 * Pre-flight credit estimate from T3Planet `Estimate.php` (not guaranteed; actual debit after upstream).
 *
 * @api
 */
final readonly class CreditsEstimate
{
    public function __construct(
        public string $featureKey,
        public string $endpoint,
        public int $estimatedTokens,
        public int $estimatedCreditUnits,
        public float $estimatedCredits,
        public int $billableTokens,
        public CreditsPricing $pricing,
        public string $defaultModel = '',
        public string $defaultBackend = '',
    ) {}

    /**
     * @param array<string, mixed> $payload Estimate.php JSON body.
     */
    public static function fromApiPayload(array $payload): self
    {
        $pricing = CreditsPricing::fromArray($payload);
        $scale = $pricing->creditUnitScale;
        $fractional = AiCreditUnits::isFractionalApi($payload);

        $estimatedCreditUnits = (int) ($payload['estimated_credit_units'] ?? 0);
        $estimatedCredits = isset($payload['estimated_credits'])
            ? (float) $payload['estimated_credits']
            : 0.0;

        if ($estimatedCreditUnits <= 0 && $estimatedCredits > 0.0) {
            $estimatedCreditUnits = AiCreditUnits::creditsToUnits($estimatedCredits, $scale);
        } elseif ($estimatedCreditUnits > 0 && $estimatedCredits <= 0.0) {
            $estimatedCredits = AiCreditUnits::unitsToCredits($estimatedCreditUnits, $scale);
        } elseif ($estimatedCreditUnits <= 0 && !$fractional) {
            $legacyWhole = (int) ($payload['estimated_credits'] ?? 0);
            $estimatedCreditUnits = $legacyWhole * $scale;
            $estimatedCredits = (float) $legacyWhole;
        }

        return new self(
            featureKey: (string) ($payload['feature_key'] ?? ''),
            endpoint: (string) ($payload['endpoint'] ?? 'charge'),
            estimatedTokens: (int) ($payload['estimated_tokens'] ?? 0),
            estimatedCreditUnits: max(0, $estimatedCreditUnits),
            estimatedCredits: max(0.0, $estimatedCredits),
            billableTokens: (int) ($payload['billable_tokens'] ?? 0),
            pricing: $pricing,
            defaultModel: (string) ($payload['default_model'] ?? ''),
            defaultBackend: (string) ($payload['default_backend'] ?? ''),
        );
    }

    public function label(): string
    {
        return $this->pricing->formatEstimate($this->estimatedCreditUnits, $this->estimatedCredits);
    }
}
