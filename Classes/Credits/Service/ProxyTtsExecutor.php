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
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\CreditsUsage;
use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Api\TtsResponse;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\AfterTtsResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\BeforeTtsRequestEvent;
use NITSAN\NsT3AF\Event\ProviderRequestFailedEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Service\BrandContextLineage;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Routes {@see TtsServiceInterface::speak()} through the T3Planet Credits Speak.php endpoint.
 *
 * Mirrors {@see ProxyAiExecutor} for text/embeddings: resolves a bearer token,
 * builds `meta_json` from {@see TtsOptions}, decodes the returned base64 audio,
 * and records telemetry. Billing key is always `text_to_speech`.
 *
 * @internal
 */
class ProxyTtsExecutor
{
    /** @var array<string, string> */
    private const FORMAT_MIME = [
        'mp3'  => 'audio/mpeg',
        'opus' => 'audio/ogg',
        'aac'  => 'audio/aac',
        'flac' => 'audio/flac',
        'wav'  => 'audio/wav',
        'pcm'  => 'audio/pcm',
    ];

    private const CALL_TTS = 'tts';

    public function __construct(
        private readonly T3PlanetApiClient $apiClient,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly CreditsFeatureKeyMapper $featureKeyMapper,
        private readonly CreditsChargeRecorder $chargeRecorder,
        private readonly EventDispatcherInterface $events,
        private readonly RequestTelemetryService $telemetry,
        private readonly LoggerInterface $logger,
    ) {}

    public function speak(string $text, TtsOptions $options): TtsResponse
    {
        $provider = $this->creditsProvider();

        // Governance choke point shared with chat/embed/image: ACL, budgets and
        // rate limits gate credits TTS via BeforeProviderRequestEvent (CTX-14).
        $governance = new BeforeProviderRequestEvent($provider, $text, $this->toAiOptions($options), self::CALL_TTS);
        $this->events->dispatch($governance);
        if ($governance->isCancelled()) {
            return new TtsResponse(
                audio: '',
                mimeType: self::FORMAT_MIME[$options->format] ?? 'audio/mpeg',
                modelId: $options->modelId ?? 't3planet',
                providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            );
        }
        $text = $governance->getPrompt();

        $before = new BeforeTtsRequestEvent($provider, $text, $options);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            return new TtsResponse(
                audio: '',
                mimeType: self::FORMAT_MIME[$options->format] ?? 'audio/mpeg',
                modelId: $options->modelId ?? 't3planet',
                providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            );
        }

        $text = $before->getText();
        $options = $before->getOptions();
        $featureKey = $this->resolveCatalogFeatureKey($options);
        $requestUuid = Uuid::v4()->toRfc4122();
        $domain = $this->domainResolver->resolve();
        $metaJson = $this->buildMetaJson($text, $options);

        $start = (int) (microtime(true) * 1000);
        try {
            $payload = $this->callWithTokenRetry(
                fn(string $token): array => $this->apiClient->speak(
                    $domain,
                    $requestUuid,
                    $featureKey,
                    $metaJson,
                    $token,
                    $options->extensionKey,
                ),
            );
        } catch (InsufficientCreditsException|CreditsApiException|ClientExceptionInterface $e) {
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, self::CALL_TTS));
            $this->logTtsFailureQuietly($provider, $options, $text, $e, $latencyMs);

            throw new AdapterRuntimeException(
                'T3Planet Credits TTS request failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }

        $latencyMs = (int) (microtime(true) * 1000) - $start;
        $audio = $this->decodeAudio($payload, $provider, $options, $text, $latencyMs);

        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];
        $usage = CreditsUsage::fromApiPayload($credits, $charged, $requestUuid, $payload);

        $this->chargeRecorder->record($requestUuid, $featureKey, $payload);

        $response = new TtsResponse(
            audio: $audio,
            mimeType: (string) ($payload['mime_type'] ?? (self::FORMAT_MIME[$options->format] ?? 'audio/mpeg')),
            modelId: (string) ($payload['model'] ?? ($options->modelId ?? 't3planet')),
            providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            latencyMs: $latencyMs,
            tokensInput: $usage->tokensInput,
            tokensTotal: $usage->tokensTotal,
            credits: $usage,
        );

        $this->events->dispatch(new AfterTtsResponseEvent($response, $text));
        // Budget/usage listeners bind to AfterProviderResponseEvent; credits TTS
        // usage must count against per-user budgets too (CTX-14).
        $this->events->dispatch(new AfterProviderResponseEvent(
            $provider,
            new AiResponse(
                content: '',
                modelId: $response->modelId,
                providerIdentifier: $response->providerIdentifier,
                tokensInput: $usage->tokensInput,
                latencyMs: $latencyMs,
                raw: ['call' => self::CALL_TTS],
                credits: $usage,
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($governance->getOptions()),
            ),
            $governance->getOptions(),
            $text,
        ));
        $this->logTtsQuietly(
            $provider,
            $options,
            $text,
            $response,
            BrandContextLineage::profileUidFromOptions($governance->getOptions()),
        );

        return $response;
    }

    private function toAiOptions(TtsOptions $options): AiOptions
    {
        return new AiOptions(
            providerIdentifier: $options->providerIdentifier,
            modelId: $options->modelId,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            requestSource: $options->requestSource,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetaJson(string $text, TtsOptions $options): array
    {
        $meta = [
            'text' => $text,
            'voice' => $options->voice,
            'format' => $options->format,
            'speed' => $options->speed,
            'client_feature_key' => trim((string) ($options->featureKey ?? '')) !== ''
                ? (string) $options->featureKey
                : 'media.tts',
        ];

        if ($options->modelId !== null && $options->modelId !== '') {
            $meta['model'] = $options->modelId;
        }

        $extensionKey = trim((string) ($options->extensionKey ?? ''));
        if ($extensionKey !== '') {
            $meta['extension_key'] = $extensionKey;
        }

        $requestSource = trim((string) ($options->requestSource ?? ''));
        if ($requestSource !== '') {
            $meta['request_source'] = $requestSource;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function decodeAudio(
        array $payload,
        Provider $provider,
        TtsOptions $options,
        string $text,
        int $latencyMs,
    ): string {
        $base64 = (string) ($payload['audio_base64'] ?? '');
        if ($base64 === '') {
            $error = new AdapterRuntimeException(
                'T3Planet Credits Speak response did not contain audio data.',
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $error, self::CALL_TTS));
            $this->logTtsFailureQuietly($provider, $options, $text, $error, $latencyMs);

            throw $error;
        }

        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            $error = new AdapterRuntimeException(
                'T3Planet Credits Speak response contained invalid base64 audio.',
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $error, self::CALL_TTS));
            $this->logTtsFailureQuietly($provider, $options, $text, $error, $latencyMs);

            throw $error;
        }

        return $binary;
    }

    private function resolveCatalogFeatureKey(TtsOptions $options): string
    {
        $clientFeatureKey = trim((string) ($options->featureKey ?? ''));

        return $this->featureKeyMapper->map(
            $clientFeatureKey,
            $this->mapperOptions($options),
            CreditsApiEndpoint::Speak,
        );
    }

    /**
     * The feature-key mapper reads `extensionKey` + `featureKey` from {@see \NITSAN\NsT3AF\Api\AiOptions}.
     */
    private function mapperOptions(TtsOptions $options): \NITSAN\NsT3AF\Api\AiOptions
    {
        return new \NITSAN\NsT3AF\Api\AiOptions(
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey ?? '',
            requestSource: $options->requestSource,
        );
    }

    /**
     * @param callable(string): array<string, mixed> $call
     * @return array<string, mixed>
     */
    private function callWithTokenRetry(callable $call): array
    {
        try {
            return $call($this->tokenResolver->resolve());
        } catch (CreditsApiException $e) {
            if (!$this->tokenResolver->invalidateOnUnauthorized($e)) {
                throw $e;
            }

            return $call($this->tokenResolver->issueFreshToken());
        }
    }

    private function creditsProvider(): Provider
    {
        return Provider::fromRow([
            'uid' => 0,
            'identifier' => CreditsProviderIdentifier::IDENTIFIER,
            'title' => 'T3Planet Credits',
            'adapter_type' => 't3planet.credits',
            'model_id' => 't3planet',
        ]);
    }

    private function logTtsQuietly(
        Provider $provider,
        TtsOptions $options,
        string $text,
        TtsResponse $response,
        ?int $brandContextProfileUid = null,
    ): void {
        try {
            $this->telemetry->logTts($provider, $options, $text, $response, $brandContextProfileUid);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Failed to write AI usage request log for T3Planet Credits TTS: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable],
            );
        }
    }

    private function logTtsFailureQuietly(
        Provider $provider,
        TtsOptions $options,
        string $text,
        \Throwable $error,
        int $latencyMs,
    ): void {
        try {
            $this->telemetry->logTtsFailure($provider, $options, $text, $error, $latencyMs);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Failed to write AI usage failure log for T3Planet Credits TTS: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable],
            );
        }
    }
}
