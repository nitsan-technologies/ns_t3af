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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\CreditsEstimate;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;

/**
 * Pre-submit credit estimates via T3Planet `Estimate.php` (token-based; not a fixed per-feature price).
 *
 * @api Use via DI when T3Planet Credits mode is active.
 */
final class CreditsEstimateService
{
    public function __construct(
        private readonly T3PlanetApiClient $apiClient,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly CreditsPricingResolver $pricingResolver,
        private readonly CreditsFeatureKeyMapper $featureKeyMapper,
    ) {}

    /**
     * @param array<string, mixed> $metaJson Same shape as Charge/Embed `meta_json` (must include `prompt` when applicable).
     * @param 'charge'|'embed'       $endpoint
     *
     * @throws CreditsApiException When credits mode is off or the API rejects the estimate.
     */
    public function estimate(
        string $featureKey,
        array $metaJson = [],
        string $endpoint = 'charge',
        ?AiOptions $options = null,
    ): CreditsEstimate {
        if ($options !== null) {
            $metaJson = CreditsMetaJsonBuilder::withAttribution($metaJson, $options);
        }

        if (!$this->creditModeResolver->isActive()) {
            throw new CreditsApiException(
                'not_active',
                400,
                'T3Planet Credits mode is not active',
            );
        }

        $clientFeatureKey = trim($featureKey);
        if ($clientFeatureKey === '') {
            throw new CreditsApiException(
                'feature_key_required',
                400,
                'feature_key is required for credit estimates',
            );
        }

        $endpoint = $endpoint === 'embed' ? 'embed' : 'charge';
        $apiEndpoint = $endpoint === 'embed' ? CreditsApiEndpoint::Embed : CreditsApiEndpoint::Charge;
        $catalogFeatureKey = $this->featureKeyMapper->map(
            $clientFeatureKey,
            $options ?? new AiOptions(featureKey: $clientFeatureKey),
            $apiEndpoint,
        );
        if ($clientFeatureKey !== '') {
            $metaJson['client_feature_key'] = $clientFeatureKey;
        }

        $domain = $this->domainResolver->resolve();
        $token = $this->tokenResolver->resolve();

        $payload = $this->apiClient->estimate($domain, $catalogFeatureKey, $metaJson, $token, $endpoint);
        $this->pricingResolver->rememberFromPayload($payload);

        return CreditsEstimate::fromApiPayload($payload);
    }
}
