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
use NITSAN\NsT3AF\Api\ImageGenerationOptions;
use NITSAN\NsT3AF\Api\ImageGenerationResponse;
use NITSAN\NsT3AF\Api\ImageGenerationServiceInterface;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\ProviderRequestFailedEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatiblePlatform;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Default {@see ImageGenerationServiceInterface} implementation.
 *
 * Duck-types `generateImages()` / `createImageVariation()` on the adapter platform.
 * Dispatches {@see BeforeProviderRequestEvent} (`callKind: image_generation`) so
 * governance / brand listeners run on the same choke point as chat (CTX-07).
 *
 * @internal
 */
final class ImageGenerationService implements ImageGenerationServiceInterface
{
    private const CALL_KIND = 'image_generation';

    public function __construct(
        private readonly ProviderLookupInterface $providers,
        private readonly AdapterRegistry $adapters,
        private readonly EventDispatcherInterface $events,
        private readonly CredentialCipher $cipher,
        private readonly RequestFactory $requestFactory,
        private readonly ?RequestTelemetryService $telemetry = null,
    ) {}

    public function generate(string $prompt, ImageGenerationOptions $options = new ImageGenerationOptions()): ImageGenerationResponse
    {
        $provider = $this->resolveProvider($options);
        $adapter = $this->resolveAdapter($provider);
        [$prompt, $options] = $this->dispatchBefore($provider, $prompt, $options);
        $modelId = $options->modelId ?? $provider->modelId;
        $platform = $this->resolveImagePlatform($adapter->platform($provider), $provider);

        $start = (int) (microtime(true) * 1000);
        try {
            $generateImages = [$platform, 'generateImages'];
            if (!is_callable($generateImages)) {
                throw new AdapterRuntimeException('Image platform generateImages method is not callable.');
            }
            /** @var list<array{url?: string, b64_json?: string, revised_prompt?: string}> $images */
            $images = $generateImages($modelId, $prompt, $options);
        } catch (AdapterRuntimeException $e) {
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, self::CALL_KIND));
            $this->telemetry?->logImageGenerationFailure($provider, $options, $prompt, $e, $latencyMs, 'generate');
            throw $e;
        } catch (\Throwable $e) {
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $rte = new AdapterRuntimeException(
                sprintf('Image generation failed for provider "%s": %s', $provider->identifier, $e->getMessage()),
                0,
                $e,
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $rte, self::CALL_KIND));
            $this->telemetry?->logImageGenerationFailure($provider, $options, $prompt, $rte, $latencyMs, 'generate');
            throw $rte;
        }

        $latencyMs = (int) (microtime(true) * 1000) - $start;
        $response = new ImageGenerationResponse(
            images: $images,
            modelId: $modelId,
            providerIdentifier: $provider->identifier,
            latencyMs: $latencyMs,
        );
        $this->telemetry?->logImageGeneration($provider, $options, $prompt, $response, 'generate');

        return $response;
    }

    public function variation(string $imagePath, ImageGenerationOptions $options = new ImageGenerationOptions()): ImageGenerationResponse
    {
        $provider = $this->resolveProvider($options);
        $adapter = $this->resolveAdapter($provider);
        [$imagePath, $options] = $this->dispatchBefore($provider, $imagePath, $options);
        $modelId = $options->modelId ?? $provider->modelId;
        $platform = $this->resolveImagePlatform($adapter->platform($provider), $provider);

        $start = (int) (microtime(true) * 1000);
        try {
            $createImageVariation = [$platform, 'createImageVariation'];
            if (!is_callable($createImageVariation)) {
                throw new AdapterRuntimeException('Image platform createImageVariation method is not callable.');
            }
            /** @var list<array{url?: string, b64_json?: string, revised_prompt?: string}> $images */
            $images = $createImageVariation($modelId, $imagePath, $options);
        } catch (AdapterRuntimeException $e) {
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, self::CALL_KIND));
            $this->telemetry?->logImageGenerationFailure($provider, $options, $imagePath, $e, $latencyMs, 'variation');
            throw $e;
        } catch (\Throwable $e) {
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $rte = new AdapterRuntimeException(
                sprintf('Image variation failed for provider "%s": %s', $provider->identifier, $e->getMessage()),
                0,
                $e,
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $rte, self::CALL_KIND));
            $this->telemetry?->logImageGenerationFailure($provider, $options, $imagePath, $rte, $latencyMs, 'variation');
            throw $rte;
        }

        $latencyMs = (int) (microtime(true) * 1000) - $start;
        $response = new ImageGenerationResponse(
            images: $images,
            modelId: $modelId,
            providerIdentifier: $provider->identifier,
            latencyMs: $latencyMs,
        );
        $this->telemetry?->logImageGeneration($provider, $options, $imagePath, $response, 'variation');

        return $response;
    }

    /**
     * @return array{0: string, 1: ImageGenerationOptions}
     */
    private function dispatchBefore(
        \NITSAN\NsT3AF\Domain\Model\Provider $provider,
        string $prompt,
        ImageGenerationOptions $options,
    ): array {
        $before = new BeforeProviderRequestEvent(
            $provider,
            $prompt,
            $this->toAiOptions($options),
            self::CALL_KIND,
        );
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            throw new AdapterRuntimeException(
                $before->getCancellationReason() ?? 'Image generation cancelled by governance.',
            );
        }

        return [
            $before->getPrompt(),
            $this->fromAiOptions($options, $before->getOptions()),
        ];
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

    private function resolveProvider(ImageGenerationOptions $options): \NITSAN\NsT3AF\Domain\Model\Provider
    {
        $provider = $options->providerIdentifier !== null
            ? $this->providers->findByIdentifier($options->providerIdentifier)
            : $this->providers->findDefault();

        if ($provider === null) {
            throw new UnknownAdapterException($options->providerIdentifier !== null
                ? sprintf('AI provider "%s" not found.', $options->providerIdentifier)
                : 'No default AI provider is configured.');
        }

        if (!$provider->isEnabled) {
            throw new UnknownAdapterException(
                sprintf('AI provider "%s" is disabled.', $provider->identifier),
            );
        }

        if (!$provider->hasCapability(Capability::IMAGE_GENERATION)) {
            throw new AdapterRuntimeException(
                sprintf('Provider "%s" does not advertise the "%s" capability.', $provider->identifier, Capability::IMAGE_GENERATION),
            );
        }

        return $provider;
    }

    private function resolveAdapter(\NITSAN\NsT3AF\Domain\Model\Provider $provider): \NITSAN\NsT3AF\Provider\Contract\AdapterInterface
    {
        if (!$this->adapters->has($provider->adapterType)) {
            throw new UnknownAdapterException(sprintf(
                'Provider "%s" references adapter type "%s" which is not registered.',
                $provider->identifier,
                $provider->adapterType,
            ));
        }

        return $this->adapters->get($provider->adapterType);
    }

    /**
     * Symfony AI bridges expose chat via `invoke()` only; image routes use the built-in
     * OpenAI-compatible HTTP client with the same provider credentials.
     */
    private function resolveImagePlatform(object $platform, \NITSAN\NsT3AF\Domain\Model\Provider $provider): object
    {
        if (method_exists($platform, 'generateImages') && method_exists($platform, 'createImageVariation')) {
            return $platform;
        }

        return new OpenAiCompatiblePlatform($provider, $this->cipher, $this->requestFactory);
    }
}
