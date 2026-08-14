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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\CreditsUsage;
use NITSAN\NsT3AF\Api\EmbeddingResponse;
use NITSAN\NsT3AF\Api\StreamSummary;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\CreditsFeatureMapping;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Http\T3PlanetSseStreamParser;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\ProviderRequestFailedEvent;
use NITSAN\NsT3AF\Service\BrandContextLineage;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
class ProxyAiExecutor
{
    private const CALL_COMPLETE = 'complete';
    private const CALL_EMBED = 'embed';
    private const CALL_STREAM = 'stream';

    public function __construct(
        private readonly T3PlanetApiClient $apiClient,
        private readonly T3PlanetSseStreamParser $sseParser,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly CreditsChargeRecorder $chargeRecorder,
        private readonly EventDispatcherInterface $events,
        private readonly RequestTelemetryService $telemetry,
        private readonly CreditsFeatureKeyMapper $featureKeyMapper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return \Generator<int, string, mixed, StreamSummary>
     */
    public function stream(string $prompt, AiOptions $options): \Generator
    {
        $provider = $this->creditsProvider();
        $mapping = $this->resolveFeatureMapping($options, CreditsApiEndpoint::Stream);
        $featureKey = $mapping->featureKey;
        $requestUuid = $this->requestUuid($options);
        $domain = $this->domainResolver->resolve();

        $before = new BeforeProviderRequestEvent($provider, $prompt, $options, self::CALL_STREAM);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            return new StreamSummary(
                content: '',
                raw: ['cancelled' => $before->getCancellationReason()],
            );
        }

        $start = (int) (microtime(true) * 1000);
        $settled = false;
        $events = null;

        try {
            $metaJson = $this->buildApiMetaJson($before->getPrompt(), $before->getOptions(), [], $mapping);
            $lines = $this->streamWithTokenRetry(
                $this->buildStreamApiCall($domain, $requestUuid, $featureKey, $metaJson, $before->getOptions()),
            );
            $events = $this->sseParser->parse($lines);
            foreach ($events as $delta) {
                if (connection_aborted()) {
                    break;
                }
                yield $delta;
            }

            if (connection_aborted()) {
                return new StreamSummary(content: '');
            }

            /** @var array<string, mixed> $payload */
            $payload = $events->getReturn();
            $settled = true;
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $summary = $this->mapUsageToStreamSummary($payload, $requestUuid);
            $this->chargeRecorder->record(
                $requestUuid,
                $featureKey,
                $payload,
                CreditsChargeRecorder::contextFromAiOptions($before->getOptions(), $latencyMs),
            );
            $response = $this->mapChargeToAiResponse($payload, $requestUuid, $latencyMs, $before->getOptions(), $mapping->legacyField);
            $this->persistCompletion($provider, $before->getOptions(), $before->getPrompt(), $response, self::CALL_STREAM);
            $this->events->dispatch(new AfterProviderResponseEvent(
                $provider,
                $response,
                $before->getOptions(),
                $before->getPrompt(),
            ));

            return $summary;
        } catch (InsufficientCreditsException $e) {
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_STREAM,
                'credits.insufficient',
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        } catch (CreditsApiException $e) {
            $reason = $e->errorCode === CreditsApiErrorCodes::UPSTREAM_AI_ERROR
                ? 'credits.upstream_ai_error'
                : 'credits.api_error';
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_STREAM,
                $reason,
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        } catch (ClientExceptionInterface $e) {
            $this->abortQuietly($domain, $requestUuid, $featureKey);
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_STREAM,
                'credits.timeout',
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        } finally {
            if (!$settled) {
                $this->abortQuietly($domain, $requestUuid, $featureKey);
            }
        }
    }

    public function complete(string $prompt, AiOptions $options): AiResponse
    {
        $provider = $this->creditsProvider();
        $mapping = $this->resolveFeatureMapping($options, CreditsApiEndpoint::Charge);
        $featureKey = $mapping->featureKey;
        $requestUuid = $this->requestUuid($options);
        $domain = $this->domainResolver->resolve();

        $before = new BeforeProviderRequestEvent($provider, $prompt, $options, self::CALL_COMPLETE);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            return new AiResponse(
                content: '',
                modelId: 't3planet',
                providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
                raw: ['cancelled' => $before->getCancellationReason()],
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($before->getOptions()),
            );
        }

        $start = (int) (microtime(true) * 1000);
        try {
            $payload = $this->postJsonWithRetries(
                fn(string $uuid): array => $this->callWithTokenRetry(
                    fn(string $token): array => $this->apiClient->charge(
                        $domain,
                        $uuid,
                        $featureKey,
                        $this->buildApiMetaJson($before->getPrompt(), $before->getOptions(), [], $mapping),
                        $token,
                        $before->getOptions(),
                    ),
                ),
                $requestUuid,
            );
        } catch (InsufficientCreditsException $e) {
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_COMPLETE,
                'credits.insufficient',
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        } catch (CreditsApiException $e) {
            $reason = $e->errorCode === 'upstream_ai_error' ? 'credits.upstream_ai_error' : 'credits.api_error';
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_COMPLETE,
                $reason,
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        } catch (ClientExceptionInterface $e) {
            $this->abortQuietly($domain, $requestUuid);
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_COMPLETE,
                'credits.timeout',
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        }

        $response = $this->mapChargeToAiResponse(
            $payload,
            $requestUuid,
            (int) (microtime(true) * 1000) - $start,
            $before->getOptions(),
            $mapping->legacyField,
        );
        $this->chargeRecorder->record(
            $requestUuid,
            $featureKey,
            $payload,
            CreditsChargeRecorder::contextFromAiOptions($before->getOptions(), $response->latencyMs),
        );
        $this->persistCompletion($provider, $before->getOptions(), $before->getPrompt(), $response);
        $this->events->dispatch(new AfterProviderResponseEvent(
            $provider,
            $response,
            $before->getOptions(),
            $before->getPrompt(),
        ));

        return $response;
    }

    /**
     * @param string|list<string> $text
     */
    public function embed(string|array $text, AiOptions $options): EmbeddingResponse
    {
        $provider = $this->creditsProvider();
        $mapping = $this->resolveFeatureMapping($options, CreditsApiEndpoint::Embed);
        $featureKey = $mapping->featureKey;
        $requestUuid = $this->requestUuid($options);
        $domain = $this->domainResolver->resolve();
        $inputs = is_array($text) ? $text : [$text];
        $prompt = is_array($text) ? implode("\n", $text) : $text;

        $before = new BeforeProviderRequestEvent($provider, $prompt, $options, self::CALL_EMBED);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            return new EmbeddingResponse(
                vectors: [],
                modelId: 't3planet',
                providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
                raw: [
                    'error' => $before->getCancellationReason() ?? 'AI provider request was cancelled.',
                ],
            );
        }

        $start = (int) (microtime(true) * 1000);
        try {
            $payload = $this->postJsonWithRetries(
                fn(string $uuid): array => $this->callWithTokenRetry(
                    fn(string $token): array => $this->apiClient->embed(
                        $domain,
                        $uuid,
                        $featureKey,
                        $this->buildApiMetaJson($prompt, $before->getOptions(), $inputs, $mapping),
                        $token,
                        $before->getOptions(),
                    ),
                ),
                $requestUuid,
            );
        } catch (InsufficientCreditsException $e) {
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_EMBED,
                'credits.insufficient',
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        } catch (CreditsApiException $e) {
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_EMBED,
                'credits.api_error',
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        } catch (ClientExceptionInterface $e) {
            $this->abortQuietly($domain, $requestUuid, $featureKey);
            $this->dispatchFailure(
                $provider,
                $e,
                self::CALL_EMBED,
                'credits.timeout',
                $before->getOptions(),
                $before->getPrompt(),
                (int) (microtime(true) * 1000) - $start,
            );
            throw $e;
        }

        $response = $this->mapEmbedToEmbeddingResponse($payload, $requestUuid, (int) (microtime(true) * 1000) - $start);
        $this->chargeRecorder->record(
            $requestUuid,
            $featureKey,
            $payload,
            CreditsChargeRecorder::contextFromAiOptions($before->getOptions(), $response->latencyMs),
        );
        $this->persistEmbedding($provider, $before->getOptions(), $before->getPrompt(), $response);
        // Budget/usage listeners bind to AfterProviderResponseEvent; credits
        // embedding usage must count against per-user budgets too (CTX-14).
        $this->events->dispatch(new AfterProviderResponseEvent(
            $provider,
            new AiResponse(
                content: '',
                modelId: $response->modelId,
                providerIdentifier: $response->providerIdentifier,
                tokensInput: $response->tokensInput,
                latencyMs: $response->latencyMs,
                raw: ['call' => self::CALL_EMBED],
                credits: $response->credits,
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($before->getOptions()),
            ),
            $before->getOptions(),
            $before->getPrompt(),
        ));

        return $response;
    }

    /**
     * @param array<string, mixed> $metaJson
     * @return callable(string): \Generator<int, string, mixed, void>
     */
    private function buildStreamApiCall(
        string $domain,
        string $requestUuid,
        string $featureKey,
        array $metaJson,
        AiOptions $options,
    ): callable {
        return function (string $token) use ($domain, $requestUuid, $featureKey, $metaJson, $options): \Generator {
            return $this->apiClient->stream($domain, $requestUuid, $featureKey, $metaJson, $token, $options);
        };
    }

    /**
     * @param callable(string): \Generator<int, string, mixed, void> $call
     * @return \Generator<int, string, mixed, void>
     */
    private function streamWithTokenRetry(callable $call): \Generator
    {
        try {
            /** @var \Generator<int, string, mixed, void> $lines */
            $lines = $call($this->tokenResolver->resolve());

            return $lines;
        } catch (CreditsApiException $e) {
            if (!$this->tokenResolver->invalidateOnUnauthorized($e)) {
                throw $e;
            }

            /** @var \Generator<int, string, mixed, void> $lines */
            $lines = $call($this->tokenResolver->issueFreshToken());

            return $lines;
        }
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

    /**
     * @param array<string, mixed> $payload
     */
    private function mapUsageToStreamSummary(array $payload, string $requestUuid): StreamSummary
    {
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];

        return new StreamSummary(
            content: (string) ($payload['content'] ?? ''),
            credits: CreditsUsage::fromApiPayload($credits, $charged, $requestUuid, $payload),
            raw: $payload,
        );
    }

    /**
     * @param callable(string): array<string, mixed> $call Receives request_uuid (may be refreshed on idempotency conflict).
     * @return array<string, mixed>
     */
    private function postJsonWithRetries(callable $call, string $requestUuid): array
    {
        try {
            return $call($requestUuid);
        } catch (CreditsApiException $e) {
            if ($e->errorCode !== CreditsApiErrorCodes::IDEMPOTENCY_CONFLICT) {
                throw $e;
            }

            return $call(Uuid::v4()->toRfc4122());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapChargeToAiResponse(
        array $payload,
        string $requestUuid,
        int $latencyMs,
        AiOptions $options = new AiOptions(),
        ?string $legacyField = null,
    ): AiResponse {
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];
        $content = $this->unwrapLegacyContent((string) ($payload['content'] ?? ''), $legacyField);

        return new AiResponse(
            content: $content,
            modelId: (string) ($payload['model'] ?? 't3planet'),
            providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            latencyMs: $latencyMs,
            raw: $payload,
            credits: CreditsUsage::fromApiPayload($credits, $charged, $requestUuid, $payload),
            appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($options),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapEmbedToEmbeddingResponse(array $payload, string $requestUuid, int $latencyMs): EmbeddingResponse
    {
        $rawVectors = $payload['vectors'] ?? $payload['embedding'] ?? [];
        $vectors = [];
        if (is_array($rawVectors)) {
            foreach ($rawVectors as $rawVector) {
                if (!is_array($rawVector)) {
                    continue;
                }
                $vectors[] = array_values(array_map(
                    static fn(mixed $value): float => is_numeric($value) ? (float) $value : 0.0,
                    $rawVector,
                ));
            }
        }
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];

        return new EmbeddingResponse(
            vectors: $vectors,
            modelId: (string) ($payload['model'] ?? 't3planet'),
            providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            latencyMs: $latencyMs,
            raw: $payload,
            credits: CreditsUsage::fromApiPayload($credits, $charged, $requestUuid, $payload),
        );
    }

    private function resolveFeatureMapping(AiOptions $options, CreditsApiEndpoint $endpoint): CreditsFeatureMapping
    {
        $clientFeatureKey = trim($options->featureKey ?? '');
        if ($clientFeatureKey === '' && $endpoint === CreditsApiEndpoint::Charge) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::FEATURE_KEY_REQUIRED,
                400,
                'featureKey is required when T3Planet Credits mode is active',
            );
        }

        return $this->featureKeyMapper->mapWithMeta($clientFeatureKey, $options, $endpoint);
    }

    private function unwrapLegacyContent(string $content, ?string $legacyField): string
    {
        if ($legacyField === null || $legacyField === '') {
            return $content;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !array_key_exists($legacyField, $decoded)) {
            return $content;
        }

        $value = $decoded[$legacyField];

        return is_string($value) ? $value : $content;
    }

    /**
     * @param list<string> $embedInputs
     * @return array<string, mixed>
     */
    private function buildApiMetaJson(
        string $prompt,
        AiOptions $options,
        array $embedInputs = [],
        ?CreditsFeatureMapping $mapping = null,
    ): array {
        $metaJson = CreditsMetaJsonBuilder::build($prompt, $options, $embedInputs);
        if ($mapping !== null && $mapping->metaAdditions !== []) {
            $metaJson = array_merge($metaJson, $mapping->metaAdditions);
        }
        $clientFeatureKey = trim($options->featureKey ?? '');
        if ($clientFeatureKey !== '') {
            $metaJson['client_feature_key'] = $clientFeatureKey;
        }

        return $metaJson;
    }

    private function requestUuid(AiOptions $options): string
    {
        if ($options->requestUuid !== '') {
            return $options->requestUuid;
        }

        return Uuid::v4()->toRfc4122();
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

    private function dispatchFailure(
        Provider $provider,
        \Throwable $error,
        string $callKind,
        string $reason,
        AiOptions $options,
        string $prompt,
        int $latencyMs = 0,
    ): void {
        $this->persistFailure($provider, $options, $prompt, $callKind, $error, $latencyMs);
        $this->events->dispatch(new ProviderRequestFailedEvent(
            $provider,
            $error,
            $callKind,
            $reason,
            $options,
            $prompt,
        ));
    }

    private function persistCompletion(
        Provider $provider,
        AiOptions $options,
        string $prompt,
        AiResponse $response,
        string $requestType = self::CALL_COMPLETE,
    ): void {
        try {
            $this->telemetry->logCompletion($provider, $options, $prompt, $response, $requestType);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Failed to write AI usage request log for T3Planet Credits completion: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable],
            );
        }
    }

    private function persistEmbedding(
        Provider $provider,
        AiOptions $options,
        string $prompt,
        EmbeddingResponse $response,
    ): void {
        try {
            $this->telemetry->logEmbedding($provider, $options, $prompt, $response);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Failed to write AI usage request log for T3Planet Credits embedding: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable],
            );
        }
    }

    private function persistFailure(
        Provider $provider,
        AiOptions $options,
        string $prompt,
        string $requestType,
        \Throwable $error,
        int $latencyMs,
    ): void {
        try {
            $this->telemetry->logFailure($provider, $options, $prompt, $requestType, $error, $latencyMs);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Failed to write AI usage request log for T3Planet Credits failure: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable],
            );
        }
    }

    private function abortQuietly(string $domain, string $requestUuid, ?string $featureKey = null): void
    {
        try {
            $payload = $this->apiClient->abort($domain, $requestUuid, $this->tokenResolver->resolve());
            if ($featureKey !== null) {
                $this->chargeRecorder->record($requestUuid, $featureKey, $payload);
            }
        } catch (\Throwable) {
        }
    }
}
