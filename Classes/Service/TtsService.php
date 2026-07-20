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
use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Api\TtsResponse;
use NITSAN\NsT3AF\Api\TtsServiceInterface;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\AfterTtsResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\BeforeTtsRequestEvent;
use NITSAN\NsT3AF\Event\ProviderRequestFailedEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatiblePlatform;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Default {@see TtsServiceInterface} implementation.
 *
 * Resolves a {@see \NITSAN\NsT3AF\Domain\Model\Provider} via
 * {@see ProviderLookupInterface}, verifies it advertises
 * {@see Capability::TTS}, then duck-types `speech()` on the adapter platform.
 *
 * @internal Inject {@see TtsServiceInterface}; this class is not semver-stable.
 */
final class TtsService implements TtsServiceInterface
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

    public function __construct(
        private readonly ProviderLookupInterface $providers,
        private readonly AdapterRegistry $adapters,
        private readonly EventDispatcherInterface $events,
        private readonly CredentialCipher $cipher,
        private readonly RequestFactory $requestFactory,
        private readonly ?RequestTelemetryService $telemetry = null,
    ) {}

    public function speak(string $text, TtsOptions $options = new TtsOptions()): TtsResponse
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

        if (!$provider->hasCapability(Capability::TTS)) {
            throw new AdapterRuntimeException(
                sprintf('Provider "%s" does not advertise the "%s" capability.', $provider->identifier, Capability::TTS),
            );
        }

        if (!$this->adapters->has($provider->adapterType)) {
            throw new UnknownAdapterException(sprintf(
                'Provider "%s" references adapter type "%s" which is not registered.',
                $provider->identifier,
                $provider->adapterType,
            ));
        }

        $adapter = $this->adapters->get($provider->adapterType);

        // Governance choke point shared with chat/embed/image: ACL, budgets and
        // rate limits gate TTS via BeforeProviderRequestEvent (CTX-14).
        $governance = new BeforeProviderRequestEvent($provider, $text, $this->toAiOptions($options), 'tts');
        $this->events->dispatch($governance);
        if ($governance->isCancelled()) {
            return new TtsResponse(
                audio: '',
                mimeType: self::FORMAT_MIME[$options->format] ?? 'audio/mpeg',
                modelId: $options->modelId ?? $provider->modelId,
                providerIdentifier: $provider->identifier,
                latencyMs: 0,
            );
        }
        $text = $governance->getPrompt();

        $before = new BeforeTtsRequestEvent($provider, $text, $options);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            $modelId = $options->modelId ?? $provider->modelId;

            return new TtsResponse(
                audio: '',
                mimeType: self::FORMAT_MIME[$options->format] ?? 'audio/mpeg',
                modelId: $modelId,
                providerIdentifier: $provider->identifier,
                latencyMs: 0,
            );
        }

        $text = $before->getText();
        $options = $before->getOptions();
        $modelId = $options->modelId ?? $provider->modelId;
        $platform = $adapter->platform($provider);
        if (!method_exists($platform, 'speech')) {
            $platform = new OpenAiCompatiblePlatform($provider, $this->cipher, $this->requestFactory);
        }

        $start = (int) (microtime(true) * 1000);
        try {
            /** @var string $audio */
            $audio = $platform->speech($modelId, $text, $options);
        } catch (AdapterRuntimeException $e) {
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, 'tts'));
            $this->telemetry?->logTtsFailure($provider, $options, $text, $e, $latencyMs);
            throw $e;
        } catch (\Throwable $e) {
            $latencyMs = (int) (microtime(true) * 1000) - $start;
            $rte = new AdapterRuntimeException(
                sprintf('TTS call failed for provider "%s": %s', $provider->identifier, $e->getMessage()),
                0,
                $e,
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $rte, 'tts'));
            $this->telemetry?->logTtsFailure($provider, $options, $text, $rte, $latencyMs);
            throw $rte;
        }

        $latencyMs = (int) (microtime(true) * 1000) - $start;
        $response = new TtsResponse(
            audio: $audio,
            mimeType: self::FORMAT_MIME[$options->format] ?? 'audio/mpeg',
            modelId: $modelId,
            providerIdentifier: $provider->identifier,
            latencyMs: $latencyMs,
        );

        $this->events->dispatch(new AfterTtsResponseEvent($response, $text));
        // Budget/usage listeners bind to AfterProviderResponseEvent; BYO TTS has
        // no token usage, but the request itself must reach those listeners (CTX-14).
        $this->events->dispatch(new AfterProviderResponseEvent(
            $provider,
            new AiResponse(
                content: '',
                modelId: $modelId,
                providerIdentifier: $provider->identifier,
                latencyMs: $latencyMs,
                raw: ['call' => 'tts'],
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($governance->getOptions()),
            ),
            $governance->getOptions(),
            $text,
        ));
        $this->telemetry?->logTts(
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
}
