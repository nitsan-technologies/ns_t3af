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
use NITSAN\NsT3AF\Api\EmbeddingResponse;
use NITSAN\NsT3AF\Api\StreamSummary;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
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
        private readonly LocalReceiptCache $receiptCache,
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
        $featureKey = $this->resolveCatalogFeatureKey($options, CreditsApiEndpoint::Stream);
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
            $metaJson = $this->buildApiMetaJson($before->getPrompt(), $before->getOptions());
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
            $this->receiptCache->storeFromCharge($requestUuid, $featureKey, $payload);
            $response = $this->mapChargeToAiResponse($payload, $requestUuid, $latencyMs, $before->getOptions());
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
        $featureKey = $this->resolveCatalogFeatureKey($options, CreditsApiEndpoint::Charge);
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
            $payload = $this->callWithTokenRetry(
                fn(string $token): array => $this->apiClient->charge(
                    $domain,
                    $requestUuid,
                    $featureKey,
                    $this->buildApiMetaJson($before->getPrompt(), $before->getOptions()),
                    $token,
                    $before->getOptions(),
                ),
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

        $response = $this->mapChargeToAiResponse($payload, $requestUuid, (int) (microtime(true) * 1000) - $start, $before->getOptions());
        $this->receiptCache->storeFromCharge($requestUuid, $featureKey, $payload);
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
        $featureKey = $this->resolveCatalogFeatureKey($options, CreditsApiEndpoint::Embed);
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
            $payload = $this->callWithTokenRetry(
                fn(string $token): array => $this->apiClient->embed(
                    $domain,
                    $requestUuid,
                    $featureKey,
                    $this->buildApiMetaJson($prompt, $before->getOptions(), $inputs),
                    $token,
                    $before->getOptions(),
                ),
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
            $this->abortQuietly($domain, $requestUuid);
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
     * @param array<string, mixed> $payload
     */
    private function mapChargeToAiResponse(array $payload, string $requestUuid, int $latencyMs, AiOptions $options = new AiOptions()): AiResponse
    {
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];

        return new AiResponse(
            content: (string) ($payload['content'] ?? ''),
            modelId: (string) ($payload['model'] ?? 't3planet'),
            providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            tokensInput: (int) ($payload['tokens_input'] ?? 0),
            tokensOutput: (int) ($payload['tokens_output'] ?? 0),
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
            tokensInput: (int) ($payload['tokens_input'] ?? 0),
            latencyMs: $latencyMs,
            raw: $payload,
            credits: CreditsUsage::fromApiPayload($credits, $charged, $requestUuid, $payload),
        );
    }

    private function resolveCatalogFeatureKey(AiOptions $options, CreditsApiEndpoint $endpoint): string
    {
        $clientFeatureKey = trim($options->featureKey ?? '');
        if ($clientFeatureKey === '' && $endpoint === CreditsApiEndpoint::Charge) {
            throw new CreditsApiException(
                CreditsApiErrorCodes::FEATURE_KEY_REQUIRED,
                400,
                'featureKey is required when T3Planet Credits mode is active',
            );
        }

        return $this->featureKeyMapper->map($clientFeatureKey, $options, $endpoint);
    }

    /**
     * @param list<string> $embedInputs
     * @return array<string, mixed>
     */
    private function buildApiMetaJson(
        string $prompt,
        AiOptions $options,
        array $embedInputs = [],
    ): array {
        $metaJson = CreditsMetaJsonBuilder::build($prompt, $options, $embedInputs);
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
            if ($featureKey !== null && ($payload['status'] ?? false) === true) {
                $this->receiptCache->storeFromCharge($requestUuid, $featureKey, $payload);
            }
        } catch (\Throwable) {
        }
    }
}
