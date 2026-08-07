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
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\EventListener\BrandContextPromptInjectionListener;

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
        private readonly ?BrandContextPromptInjectionListener $brandContextInjection = null,
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
            // Charge/Stream inject brand context via BeforeProviderRequestEvent;
            // apply the same expansion here so the estimate token count matches
            // the actual charge (CTX-09).
            if ($endpoint !== 'embed') {
                $metaJson = $this->applyBrandContext($metaJson, $options);
            }
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
        $metaJson['client_feature_key'] = $clientFeatureKey;

        $domain = $this->domainResolver->resolve();
        $token = $this->tokenResolver->resolve();

        $payload = $this->apiClient->estimate($domain, $catalogFeatureKey, $metaJson, $token, $endpoint);
        $this->pricingResolver->rememberFromPayload($payload);

        return CreditsEstimate::fromApiPayload($payload);
    }

    /**
     * Runs the brand-context injection listener over the estimate payload so the
     * estimated prompt matches what {@see ProxyAiExecutor} will actually send.
     *
     * @param array<string, mixed> $metaJson
     * @return array<string, mixed>
     */
    private function applyBrandContext(array $metaJson, AiOptions $options): array
    {
        if ($this->brandContextInjection === null) {
            return $metaJson;
        }

        $prompt = is_string($metaJson['prompt'] ?? null) ? $metaJson['prompt'] : '';

        $extra = $options->extra;
        if (!isset($extra['messages']) && is_array($metaJson['messages'] ?? null)) {
            $extra['messages'] = $metaJson['messages'];
        }
        $systemPrompt = $options->systemPrompt;
        if ($systemPrompt === null && is_string($metaJson['system_prompt'] ?? null)) {
            $systemPrompt = $metaJson['system_prompt'];
        }

        $event = new BeforeProviderRequestEvent(
            $this->estimateProvider(),
            $prompt,
            new AiOptions(
                providerIdentifier: $options->providerIdentifier,
                modelId: $options->modelId,
                temperature: $options->temperature,
                systemPrompt: $systemPrompt,
                maxTokens: $options->maxTokens,
                noCache: $options->noCache,
                extensionKey: $options->extensionKey,
                featureKey: $options->featureKey,
                featureLabel: $options->featureLabel,
                requestSource: $options->requestSource,
                contentEntityType: $options->contentEntityType,
                contentEntityUid: $options->contentEntityUid,
                pageId: $options->pageId,
                requestUuid: $options->requestUuid,
                extra: $extra,
            ),
            'complete',
        );
        ($this->brandContextInjection)($event);

        if ($prompt !== '') {
            $metaJson['prompt'] = $event->getPrompt();
        }

        $mutated = $event->getOptions();
        $mutatedMessages = $mutated->extra['messages'] ?? null;
        if (is_array($mutatedMessages) && isset($metaJson['messages'])) {
            $metaJson['messages'] = $mutatedMessages;
        }
        $mutatedSystem = trim((string) $mutated->systemPrompt);
        if ($mutatedSystem !== '') {
            $metaJson['system_prompt'] = $mutatedSystem;
        }

        return $metaJson;
    }

    private function estimateProvider(): Provider
    {
        return Provider::fromRow([
            'uid' => 0,
            'identifier' => CreditsProviderIdentifier::IDENTIFIER,
            'title' => 'T3Planet Credits',
            'adapter_type' => 't3planet.credits',
            'model_id' => 't3planet',
        ]);
    }
}
