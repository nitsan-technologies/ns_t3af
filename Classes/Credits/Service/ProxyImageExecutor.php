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
use NITSAN\NsT3AF\Api\ImageGenerationOptions;
use NITSAN\NsT3AF\Api\ImageGenerationResponse;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\ProviderRequestFailedEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Service\BrandContextLineage;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Routes {@see ImageGenerationServiceInterface} through the T3Planet Credits Image.php endpoint.
 *
 * @internal
 */
class ProxyImageExecutor
{
    private const CALL_KIND = 'image_generation';

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

    public function generate(string $prompt, ImageGenerationOptions $options): ImageGenerationResponse
    {
        return $this->execute($prompt, $options, 'generate');
    }

    public function variation(string $imagePath, ImageGenerationOptions $options): ImageGenerationResponse
    {
        return $this->execute($imagePath, $options, 'variation');
    }

    private function execute(string $input, ImageGenerationOptions $options, string $mode): ImageGenerationResponse
    {
        $provider = $this->creditsProvider();
        $governance = new BeforeProviderRequestEvent(
            $provider,
            $input,
            $this->toAiOptions($options),
            self::CALL_KIND,
        );
        $this->events->dispatch($governance);
        if ($governance->isCancelled()) {
            throw new AdapterRuntimeException(
                $governance->getCancellationReason() ?? 'Image generation cancelled by governance.',
            );
        }

        $input = $governance->getPrompt();
        $options = $this->fromAiOptions($options, $governance->getOptions());
        $featureKey = $this->resolveCatalogFeatureKey($options);
        $requestUuid = Uuid::v4()->toRfc4122();
        $domain = $this->domainResolver->resolve();
        $metaJson = $this->buildMetaJson($input, $options, $mode);

        $start = (int) (microtime(true) * 1000);
        try {
            $payload = $this->callWithTokenRetry(
                fn(string $token): array => $this->apiClient->generateImage(
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
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, self::CALL_KIND));
            $this->logFailureQuietly($provider, $options, $input, $e, $latencyMs, $mode);

            throw new AdapterRuntimeException(
                'T3Planet Credits image request failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }

        $latencyMs = (int) (microtime(true) * 1000) - $start;
        $images = $this->decodeImages($payload, $provider, $options, $input, $latencyMs, $mode);
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $charged = is_array($payload['charged'] ?? null) ? $payload['charged'] : [];
        $usage = CreditsUsage::fromApiPayload($credits, $charged, $requestUuid, $payload);

        $this->chargeRecorder->record($requestUuid, $featureKey, $payload);

        $response = new ImageGenerationResponse(
            images: $images,
            modelId: (string) ($payload['model'] ?? ($options->modelId ?? 't3planet')),
            providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            latencyMs: $latencyMs,
            tokensInput: $usage->tokensInput,
            tokensTotal: $usage->tokensTotal,
            credits: $usage,
        );

        $this->events->dispatch(new AfterProviderResponseEvent(
            $provider,
            new AiResponse(
                content: '',
                modelId: $response->modelId,
                providerIdentifier: $response->providerIdentifier,
                tokensInput: $usage->tokensInput,
                latencyMs: $latencyMs,
                raw: ['call' => self::CALL_KIND, 'mode' => $mode],
                credits: $usage,
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($governance->getOptions()),
            ),
            $governance->getOptions(),
            $input,
        ));
        $this->logSuccessQuietly(
            $provider,
            $options,
            $input,
            $response,
            $mode,
            BrandContextLineage::profileUidFromOptions($governance->getOptions()),
        );

        return $response;
    }

    private function toAiOptions(ImageGenerationOptions $options): AiOptions
    {
        return new AiOptions(
            providerIdentifier: $options->providerIdentifier,
            modelId: $options->modelId,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            requestSource: $options->requestSource,
            extra: [
                'imageSize' => $options->size,
                'imageCount' => $options->count,
            ],
        );
    }

    private function fromAiOptions(ImageGenerationOptions $original, AiOptions $ai): ImageGenerationOptions
    {
        $size = $ai->extra['imageSize'] ?? $original->size;
        $count = $ai->extra['imageCount'] ?? $original->count;

        return new ImageGenerationOptions(
            providerIdentifier: $ai->providerIdentifier ?? $original->providerIdentifier,
            modelId: $ai->modelId ?? $original->modelId,
            size: is_string($size) && $size !== '' ? $size : $original->size,
            count: is_int($count) && $count > 0 ? $count : $original->count,
            extensionKey: $ai->extensionKey ?? $original->extensionKey,
            featureKey: $ai->featureKey ?? $original->featureKey,
            requestSource: $ai->requestSource ?? $original->requestSource,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetaJson(string $input, ImageGenerationOptions $options, string $mode): array
    {
        $clientFeatureKey = trim((string) ($options->featureKey ?? ''));
        $meta = [
            'mode' => $mode,
            'size' => $options->size,
            'count' => $options->count,
            'client_feature_key' => $clientFeatureKey !== '' ? $clientFeatureKey : 'media.image',
        ];

        if ($mode === 'variation') {
            $meta['source_image'] = $this->encodeSourceImage($input);
        } else {
            $meta['prompt'] = $input;
        }

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

    private function encodeSourceImage(string $imagePath): string
    {
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new AdapterRuntimeException(
                sprintf('T3Planet Credits image variation source is not readable: %s', $imagePath),
            );
        }

        $binary = file_get_contents($imagePath);
        if ($binary === false || $binary === '') {
            throw new AdapterRuntimeException(
                sprintf('T3Planet Credits image variation source is empty: %s', $imagePath),
            );
        }

        return base64_encode($binary);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{url?: string, b64_json?: string, revised_prompt?: string}>
     */
    private function decodeImages(
        array $payload,
        Provider $provider,
        ImageGenerationOptions $options,
        string $input,
        int $latencyMs,
        string $mode,
    ): array {
        $images = $payload['images'] ?? null;
        if (is_array($images) && $images !== []) {
            /** @var list<array{url?: string, b64_json?: string, revised_prompt?: string}> $normalized */
            $normalized = [];
            foreach ($images as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $normalized[] = $image;
            }
            if ($normalized !== []) {
                return $normalized;
            }
        }

        $base64Items = $payload['images_base64'] ?? null;
        if (is_array($base64Items) && $base64Items !== []) {
            $normalized = [];
            foreach ($base64Items as $base64) {
                if (!is_string($base64) || $base64 === '') {
                    continue;
                }
                $normalized[] = ['b64_json' => $base64];
            }
            if ($normalized !== []) {
                return $normalized;
            }
        }

        $single = (string) ($payload['image_base64'] ?? '');
        if ($single !== '') {
            return [['b64_json' => $single]];
        }

        $error = new AdapterRuntimeException(
            'T3Planet Credits Image response did not contain image data.',
        );
        $this->events->dispatch(new ProviderRequestFailedEvent($provider, $error, self::CALL_KIND));
        $this->logFailureQuietly($provider, $options, $input, $error, $latencyMs, $mode);

        throw $error;
    }

    private function resolveCatalogFeatureKey(ImageGenerationOptions $options): string
    {
        return $this->featureKeyMapper->map(
            trim((string) ($options->featureKey ?? '')),
            new AiOptions(
                extensionKey: $options->extensionKey,
                featureKey: $options->featureKey ?? '',
                requestSource: $options->requestSource,
            ),
            CreditsApiEndpoint::Image,
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

    private function logSuccessQuietly(
        Provider $provider,
        ImageGenerationOptions $options,
        string $input,
        ImageGenerationResponse $response,
        string $mode,
        ?int $brandContextProfileUid = null,
    ): void {
        try {
            $this->telemetry->logImageGeneration($provider, $options, $input, $response, $mode);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Failed to write AI usage request log for T3Planet Credits image generation: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable],
            );
        }
    }

    private function logFailureQuietly(
        Provider $provider,
        ImageGenerationOptions $options,
        string $input,
        \Throwable $error,
        int $latencyMs,
        string $mode,
    ): void {
        try {
            $this->telemetry->logImageGenerationFailure($provider, $options, $input, $error, $latencyMs, $mode);
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'Failed to write AI usage failure log for T3Planet Credits image generation: {message}',
                ['message' => $throwable->getMessage(), 'exception' => $throwable],
            );
        }
    }
}
