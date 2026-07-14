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
 * Credit debit snapshot returned by T3Planet Credits API (Charge/Embed).
 *
 * @api
 */
final readonly class CreditsUsage
{
    public function __construct(
        public int $chargedUnits,
        public float $charged,
        public string $bucket,
        public string $featureKey,
        public string $serverRequestId,
        public int $balanceFreeUnits,
        public float $balanceFree,
        public int $balancePaidUnits,
        public float $balancePaid,
        public int $planUsedUnits,
        public float $planUsed,
        public int $planTotalUnits,
        public float $planTotal,
        public string $planName,
        public int $planExpiresAt,
        public int $tokensInput = 0,
        public int $tokensOutput = 0,
        public int $tokensTotal = 0,
        public int $chargedTokensTotal = 0,
        public string $model = '',
        public ?CreditsPricing $pricing = null,
    ) {}

    /**
     * @param array<string, mixed>      $credits
     * @param array<string, mixed>      $charged
     * @param array<string, mixed>      $payload Full Charge/Embed response (tokens + pricing).
     */
    public static function fromApiPayload(
        array $credits,
        array $charged,
        string $requestUuid,
        array $payload = [],
    ): self {
        $tokensInput = (int) ($payload['tokens_input'] ?? 0);
        $tokensOutput = (int) ($payload['tokens_output'] ?? 0);
        $tokensTotal = (int) (
            $payload['tokens_total']
            ?? $charged['tokens_total']
            ?? ($tokensInput + $tokensOutput)
        );

        $pricing = isset($payload['pricing']) ? CreditsPricing::fromArray($payload) : null;
        $cost = AiCreditUnits::parseCost($payload, $charged);
        $buckets = AiCreditUnits::parseBalanceBuckets(
            $credits,
            $pricing?->creditUnitScale ?? AiCreditUnits::SCALE,
        );

        return new self(
            chargedUnits: $cost['units'],
            charged: $cost['credits'],
            bucket: (string) ($charged['bucket'] ?? ''),
            featureKey: (string) ($charged['feature_key'] ?? ''),
            serverRequestId: $requestUuid,
            balanceFreeUnits: $buckets['freeUnits'],
            balanceFree: $buckets['freeCredits'],
            balancePaidUnits: $buckets['paidUnits'],
            balancePaid: $buckets['paidCredits'],
            planUsedUnits: $buckets['planUsedUnits'],
            planUsed: $buckets['planUsedCredits'],
            planTotalUnits: $buckets['planTotalUnits'],
            planTotal: $buckets['planTotalCredits'],
            planName: (string) ($credits['plan_name'] ?? 'none'),
            planExpiresAt: (int) ($credits['expires_at'] ?? 0),
            tokensInput: $tokensInput,
            tokensOutput: $tokensOutput,
            tokensTotal: $tokensTotal,
            chargedTokensTotal: (int) ($charged['tokens_total'] ?? $tokensTotal),
            model: (string) ($charged['model'] ?? $payload['model'] ?? ''),
            pricing: $pricing,
        );
    }
}
