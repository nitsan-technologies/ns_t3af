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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\CreditsUsage;
use NITSAN\NsT3AF\Api\EmbeddingResponse;
use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Api\TtsResponse;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Governance\PrivacyLevel;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Writes privacy-safe request telemetry rows for dashboard analytics.
 *
 * @internal
 */
final class RequestTelemetryService
{
    public function __construct(
        private readonly RequestLogRepository $requestLogs,
        private readonly RequestQualityResolver $qualityResolver,
    ) {}

    public function logCompletion(
        Provider $provider,
        AiOptions $options,
        string $prompt,
        AiResponse $response,
        string $requestType = 'complete',
    ): void {
        $this->persist($provider, [
            'provider_uid' => $provider->uid,
            'provider_identifier' => $provider->identifier,
            'extension_key' => $this->extensionKey($options),
            'feature_key' => $this->featureKey($options),
            'feature_label' => $this->normalizeText($options->featureLabel),
            'request_source' => $this->requestSource($options),
            'content_entity_type' => $this->normalizeText($options->contentEntityType),
            'content_entity_uid' => $options->contentEntityUid ?? 0,
            'request_type' => $requestType,
            'model_requested' => $provider->modelId,
            'model_used' => $response->modelId !== '' ? $response->modelId : $provider->modelId,
            'success' => 1,
            'error_code' => '',
            'error_class' => '',
            'prompt_fingerprint' => hash('sha256', $prompt),
            'prompt_tokens' => max(0, $response->tokensInput),
            'completion_tokens' => max(0, $response->tokensOutput),
            'total_tokens' => $this->totalTokensFromResponse($response),
            'latency_ms' => max(0, $response->latencyMs),
            'cached' => $response->cached ? 1 : 0,
            'estimated_cost' => $this->loggedCost(
                $provider,
                $response->tokensInput,
                $response->tokensOutput,
                $response->credits,
            ),
            'credits_used' => (float) $this->creditsCharged($response->credits),
            'currency' => $this->currency($provider->pricingCurrency),
            ...$this->qualityResolver->resolveForLog($response, $options),
            'raw_meta' => json_encode([
                'adapter_type' => $provider->adapterType,
                'no_cache' => $options->noCache,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function logEmbedding(
        Provider $provider,
        AiOptions $options,
        string $text,
        EmbeddingResponse $response,
    ): void {
        $this->persist($provider, [
            'provider_uid' => $provider->uid,
            'provider_identifier' => $provider->identifier,
            'extension_key' => $this->extensionKey($options),
            'feature_key' => $this->featureKey($options),
            'feature_label' => $this->normalizeText($options->featureLabel),
            'request_source' => $this->requestSource($options),
            'content_entity_type' => $this->normalizeText($options->contentEntityType),
            'content_entity_uid' => $options->contentEntityUid ?? 0,
            'request_type' => 'embed',
            'model_requested' => $provider->modelId,
            'model_used' => $response->modelId !== '' ? $response->modelId : $provider->modelId,
            'success' => 1,
            'error_code' => '',
            'error_class' => '',
            'prompt_fingerprint' => hash('sha256', $text),
            'prompt_tokens' => max(0, $response->tokensInput),
            'completion_tokens' => 0,
            'total_tokens' => $this->totalTokensFromEmbedding($response),
            'latency_ms' => max(0, $response->latencyMs),
            'cached' => 0,
            'estimated_cost' => $this->loggedCost(
                $provider,
                $response->tokensInput,
                0,
                $response->credits,
            ),
            'credits_used' => (float) $this->creditsCharged($response->credits),
            'currency' => $this->currency($provider->pricingCurrency),
            'raw_meta' => json_encode([
                'adapter_type' => $provider->adapterType,
                'vector_count' => count($response->vectors),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function logTts(
        Provider $provider,
        TtsOptions $options,
        string $text,
        TtsResponse $response,
    ): void {
        $this->persist($provider, [
            'provider_uid'         => $provider->uid,
            'provider_identifier'  => $provider->identifier,
            'extension_key'        => $this->ttsExtensionKey($options),
            'feature_key'          => $this->ttsFeatureKey($options),
            'feature_label'        => '',
            'request_source'       => $this->ttsRequestSource($options),
            'content_entity_type'  => '',
            'content_entity_uid'   => 0,
            'request_type'         => 'tts',
            'model_requested'      => $options->modelId ?? $provider->modelId,
            'model_used'           => $response->modelId !== '' ? $response->modelId : $provider->modelId,
            'success'              => 1,
            'error_code'           => '',
            'error_class'          => '',
            'prompt_fingerprint'   => hash('sha256', $text),
            'prompt_tokens'        => max(0, $response->tokensInput),
            'completion_tokens'    => 0,
            'total_tokens'         => $this->totalTokensFromTts($response),
            'latency_ms'           => max(0, $response->latencyMs),
            'cached'               => 0,
            'estimated_cost'       => $this->loggedCost(
                $provider,
                $response->tokensInput,
                0,
                $response->credits,
            ),
            'credits_used'         => (float) $this->creditsCharged($response->credits),
            'currency'             => $this->currency($provider->pricingCurrency),
            'raw_meta'             => json_encode([
                'adapter_type' => $provider->adapterType,
                'voice'        => $options->voice,
                'format'       => $options->format,
                'speed'        => $options->speed,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function logTranslation(
        Provider $provider,
        string $extensionKey,
        string $featureKey,
        string $sourceText,
        string $translatedText,
        string $requestSource = 'backend_localization',
        int $latencyMs = 0,
        string $sourceLanguage = '',
        string $targetLanguage = '',
    ): void {
        $sourceChars = mb_strlen($sourceText);
        $targetChars = mb_strlen($translatedText);
        $normalizedExtensionKey = $this->normalizeText($extensionKey);
        $normalizedFeatureKey = $this->normalizeText($featureKey);
        $normalizedRequestSource = $this->normalizeText($requestSource);

        $this->persist($provider, [
            'provider_uid' => $provider->uid,
            'provider_identifier' => $provider->identifier,
            'extension_key' => $normalizedExtensionKey !== '' ? $normalizedExtensionKey : 'unknown',
            'feature_key' => $normalizedFeatureKey !== '' ? $normalizedFeatureKey : 'translation',
            'feature_label' => '',
            'request_source' => $normalizedRequestSource !== '' ? $normalizedRequestSource : 'backend_localization',
            'content_entity_type' => '',
            'content_entity_uid' => 0,
            'request_type' => 'translate',
            'model_requested' => $provider->modelId,
            'model_used' => $provider->modelId !== '' ? $provider->modelId : $normalizedFeatureKey,
            'success' => 1,
            'error_code' => '',
            'error_class' => '',
            'prompt_fingerprint' => hash('sha256', $sourceText),
            'prompt_tokens' => $sourceChars,
            'completion_tokens' => $targetChars,
            'total_tokens' => $sourceChars + $targetChars,
            'latency_ms' => max(0, $latencyMs),
            'cached' => 0,
            'estimated_cost' => $this->estimatedCost($provider, $sourceChars, $targetChars),
            'credits_used' => 0.0,
            'currency' => $this->currency($provider->pricingCurrency),
            'raw_meta' => json_encode([
                'adapter_type' => $provider->adapterType,
                'source_language' => $this->normalizeText($sourceLanguage),
                'target_language' => $this->normalizeText($targetLanguage),
                'source_chars' => $sourceChars,
                'target_chars' => $targetChars,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function logTranslationFailure(
        Provider $provider,
        string $extensionKey,
        string $featureKey,
        string $sourceText,
        \Throwable $error,
        string $requestSource = 'backend_localization',
        int $latencyMs = 0,
        string $sourceLanguage = '',
        string $targetLanguage = '',
    ): void {
        $normalizedExtensionKey = $this->normalizeText($extensionKey);
        $normalizedFeatureKey = $this->normalizeText($featureKey);
        $normalizedRequestSource = $this->normalizeText($requestSource);

        $this->persist($provider, [
            'provider_uid' => $provider->uid,
            'provider_identifier' => $provider->identifier,
            'extension_key' => $normalizedExtensionKey !== '' ? $normalizedExtensionKey : 'unknown',
            'feature_key' => $normalizedFeatureKey !== '' ? $normalizedFeatureKey : 'translation',
            'feature_label' => '',
            'request_source' => $normalizedRequestSource !== '' ? $normalizedRequestSource : 'backend_localization',
            'content_entity_type' => '',
            'content_entity_uid' => 0,
            'request_type' => 'translate',
            'model_requested' => $provider->modelId,
            'model_used' => '',
            'success' => 0,
            'error_code' => (string) $error->getCode(),
            'error_class' => $error::class,
            'prompt_fingerprint' => hash('sha256', $sourceText),
            'prompt_tokens' => mb_strlen($sourceText),
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'latency_ms' => max(0, $latencyMs),
            'cached' => 0,
            'estimated_cost' => 0.0,
            'credits_used' => 0.0,
            'currency' => $this->currency($provider->pricingCurrency),
            'raw_meta' => json_encode($this->failureMeta($provider, $error, [
                'source_language' => $this->normalizeText($sourceLanguage),
                'target_language' => $this->normalizeText($targetLanguage),
            ]), JSON_THROW_ON_ERROR),
        ]);
    }

    public function logTtsFailure(
        Provider $provider,
        TtsOptions $options,
        string $text,
        \Throwable $error,
        int $latencyMs = 0,
    ): void {
        $this->persist($provider, [
            'provider_uid'         => $provider->uid,
            'provider_identifier'  => $provider->identifier,
            'extension_key'        => $this->ttsExtensionKey($options),
            'feature_key'          => $this->ttsFeatureKey($options),
            'feature_label'        => '',
            'request_source'       => $this->ttsRequestSource($options),
            'content_entity_type'  => '',
            'content_entity_uid'   => 0,
            'request_type'         => 'tts',
            'model_requested'      => $options->modelId ?? $provider->modelId,
            'model_used'           => '',
            'success'              => 0,
            'error_code'           => (string) $error->getCode(),
            'error_class'          => $error::class,
            'prompt_fingerprint'   => hash('sha256', $text),
            'prompt_tokens'        => 0,
            'completion_tokens'    => 0,
            'total_tokens'         => 0,
            'latency_ms'           => max(0, $latencyMs),
            'cached'               => 0,
            'estimated_cost'       => 0.0,
            'credits_used'         => 0.0,
            'currency'             => $this->currency($provider->pricingCurrency),
            'raw_meta'             => json_encode($this->failureMeta($provider, $error, [
                'voice'  => $options->voice,
                'format' => $options->format,
            ]), JSON_THROW_ON_ERROR),
        ]);
    }

    public function logImageGeneration(
        Provider $provider,
        \NITSAN\NsT3AF\Api\ImageGenerationOptions $options,
        string $prompt,
        \NITSAN\NsT3AF\Api\ImageGenerationResponse $response,
        string $operation,
    ): void {
        $this->persist($provider, [
            'provider_uid'         => $provider->uid,
            'provider_identifier'  => $provider->identifier,
            'extension_key'        => $this->imageExtensionKey($options),
            'feature_key'          => $this->imageFeatureKey($options),
            'feature_label'        => '',
            'request_source'       => $this->imageRequestSource($options),
            'content_entity_type'  => '',
            'content_entity_uid'   => 0,
            'request_type'         => 'image_generation',
            'model_requested'      => $options->modelId ?? $provider->modelId,
            'model_used'           => $response->modelId !== '' ? $response->modelId : $provider->modelId,
            'success'              => 1,
            'error_code'           => '',
            'error_class'          => '',
            'prompt_fingerprint'   => hash('sha256', $prompt),
            'prompt_tokens'        => 0,
            'completion_tokens'    => 0,
            'total_tokens'         => 0,
            'latency_ms'           => max(0, $response->latencyMs),
            'cached'               => 0,
            'estimated_cost'       => 0.0,
            'credits_used'         => 0.0,
            'currency'             => $this->currency($provider->pricingCurrency),
            'raw_meta'             => json_encode([
                'adapter_type' => $provider->adapterType,
                'operation'    => $operation,
                'size'         => $options->size,
                'count'        => $options->count,
                'image_count'  => count($response->images),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function logImageGenerationFailure(
        Provider $provider,
        \NITSAN\NsT3AF\Api\ImageGenerationOptions $options,
        string $prompt,
        \Throwable $error,
        int $latencyMs = 0,
        string $operation = 'generate',
    ): void {
        $this->persist($provider, [
            'provider_uid'         => $provider->uid,
            'provider_identifier'  => $provider->identifier,
            'extension_key'        => $this->imageExtensionKey($options),
            'feature_key'          => $this->imageFeatureKey($options),
            'feature_label'        => '',
            'request_source'       => $this->imageRequestSource($options),
            'content_entity_type'  => '',
            'content_entity_uid'   => 0,
            'request_type'         => 'image_generation',
            'model_requested'      => $options->modelId ?? $provider->modelId,
            'model_used'           => '',
            'success'              => 0,
            'error_code'           => (string) $error->getCode(),
            'error_class'          => $error::class,
            'prompt_fingerprint'   => hash('sha256', $prompt),
            'prompt_tokens'        => 0,
            'completion_tokens'    => 0,
            'total_tokens'         => 0,
            'latency_ms'           => max(0, $latencyMs),
            'cached'               => 0,
            'estimated_cost'       => 0.0,
            'credits_used'         => 0.0,
            'currency'             => $this->currency($provider->pricingCurrency),
            'raw_meta'             => json_encode($this->failureMeta($provider, $error, [
                'operation' => $operation,
            ]), JSON_THROW_ON_ERROR),
        ]);
    }

    public function logFailure(
        Provider $provider,
        AiOptions $options,
        string $prompt,
        string $requestType,
        \Throwable $error,
        int $latencyMs = 0,
    ): void {
        $this->persist($provider, [
            'provider_uid' => $provider->uid,
            'provider_identifier' => $provider->identifier,
            'extension_key' => $this->extensionKey($options),
            'feature_key' => $this->featureKey($options),
            'feature_label' => $this->normalizeText($options->featureLabel),
            'request_source' => $this->requestSource($options),
            'content_entity_type' => $this->normalizeText($options->contentEntityType),
            'content_entity_uid' => $options->contentEntityUid ?? 0,
            'request_type' => $requestType,
            'model_requested' => $provider->modelId,
            'model_used' => '',
            'success' => 0,
            'error_code' => (string) $error->getCode(),
            'error_class' => $error::class,
            'prompt_fingerprint' => hash('sha256', $prompt),
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'latency_ms' => max(0, $latencyMs),
            'cached' => 0,
            'estimated_cost' => 0.0,
            'credits_used' => 0.0,
            'currency' => $this->currency($provider->pricingCurrency),
            'raw_meta' => json_encode($this->failureMeta($provider, $error), JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function failureMeta(Provider $provider, \Throwable $error, array $extra = []): array
    {
        return array_merge([
            'adapter_type' => $provider->adapterType,
            'message' => $error->getMessage(),
        ], $extra);
    }

    /**
     * Apply the resolved privacy level, attach the backend user id, then write.
     *
     * - {@see PrivacyLevel::None}    — drop the row entirely.
     * - {@see PrivacyLevel::Reduced} — keep counters, strip prompt fingerprint
     *   and raw metadata.
     *
     * @param array<string, int|float|string|null> $payload
     */
    private function persist(Provider $provider, array $payload): void
    {
        $privacy = $this->resolvePrivacyLevel($provider);
        if ($privacy === PrivacyLevel::None) {
            return;
        }
        if ($privacy === PrivacyLevel::Reduced) {
            $payload['prompt_fingerprint'] = '';
            $payload['raw_meta'] = '{}';
        }
        $payload['be_user_id'] = $this->currentBeUserId();

        $this->requestLogs->add($payload);
    }

    private function resolvePrivacyLevel(Provider $provider): PrivacyLevel
    {
        $providerLevel = PrivacyLevel::fromString($provider->privacyLevel);
        $userLevel = $this->userPrivacyLevel();

        return $userLevel === null ? $providerLevel : $providerLevel->strictest($userLevel);
    }

    private function userPrivacyLevel(): ?PrivacyLevel
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return null;
        }
        $value = trim((string) ($user->getTSConfig()['nst3af.']['privacyLevel'] ?? ''));

        return $value === '' ? null : PrivacyLevel::fromString($value);
    }

    private function currentBeUserId(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? (int) ($user->user['uid'] ?? 0) : 0;
    }

    private function extensionKey(AiOptions $options): string
    {
        $value = $this->normalizeText($options->extensionKey);

        return $value !== '' ? $value : 'unknown';
    }

    private function featureKey(AiOptions $options): string
    {
        $value = $this->normalizeText($options->featureKey);

        return $value !== '' ? $value : 'default';
    }

    private function requestSource(AiOptions $options): string
    {
        $value = $this->normalizeText($options->requestSource);

        return $value !== '' ? $value : 'unknown';
    }

    private function normalizeText(?string $value): string
    {
        return trim((string) $value);
    }

    private function estimatedCost(Provider $provider, int $promptTokens, int $completionTokens): float
    {
        $input = ((float) $promptTokens / 1000000) * $provider->pricingInputPer1m;
        $output = ((float) $completionTokens / 1000000) * $provider->pricingOutputPer1m;

        return round($input + $output, 6);
    }

    /**
     * BYO providers: derive cost from configured per-1M token rates.
     * T3Planet Credits: use the charged amount returned by the credits API.
     */
    private function loggedCost(
        Provider $provider,
        int $promptTokens,
        int $completionTokens,
        ?CreditsUsage $credits,
    ): float {
        $charged = $this->creditsCharged($credits);
        if ($charged > 0.0) {
            return round($charged, 6);
        }

        return $this->estimatedCost($provider, $promptTokens, $completionTokens);
    }

    private function currency(string $currency): string
    {
        $normalized = strtoupper(trim($currency));
        if ($normalized === '' || strlen($normalized) !== 3) {
            return 'USD';
        }

        return $normalized;
    }

    private function creditsCharged(?CreditsUsage $credits): float
    {
        return $credits?->charged ?? 0.0;
    }

    private function totalTokensFromResponse(AiResponse $response): int
    {
        if ($response->credits !== null && $response->credits->tokensTotal > 0) {
            return $response->credits->tokensTotal;
        }

        return max(0, $response->tokensInput + $response->tokensOutput);
    }

    private function totalTokensFromEmbedding(EmbeddingResponse $response): int
    {
        if ($response->credits !== null && $response->credits->tokensTotal > 0) {
            return $response->credits->tokensTotal;
        }

        return max(0, $response->tokensInput);
    }

    private function totalTokensFromTts(TtsResponse $response): int
    {
        if ($response->credits !== null && $response->credits->tokensTotal > 0) {
            return $response->credits->tokensTotal;
        }

        if ($response->tokensTotal > 0) {
            return $response->tokensTotal;
        }

        return max(0, $response->tokensInput);
    }

    private function ttsExtensionKey(TtsOptions $options): string
    {
        $value = $this->normalizeText($options->extensionKey);
        return $value !== '' ? $value : 'unknown';
    }

    private function ttsFeatureKey(TtsOptions $options): string
    {
        $value = $this->normalizeText($options->featureKey);
        return $value !== '' ? $value : 'tts';
    }

    private function ttsRequestSource(TtsOptions $options): string
    {
        $value = $this->normalizeText($options->requestSource);
        return $value !== '' ? $value : 'unknown';
    }

    private function imageExtensionKey(\NITSAN\NsT3AF\Api\ImageGenerationOptions $options): string
    {
        $value = $this->normalizeText($options->extensionKey);
        return $value !== '' ? $value : 'unknown';
    }

    private function imageFeatureKey(\NITSAN\NsT3AF\Api\ImageGenerationOptions $options): string
    {
        $value = $this->normalizeText($options->featureKey);
        return $value !== '' ? $value : 'image_generation';
    }

    private function imageRequestSource(\NITSAN\NsT3AF\Api\ImageGenerationOptions $options): string
    {
        $value = $this->normalizeText($options->requestSource);
        return $value !== '' ? $value : 'unknown';
    }
}
