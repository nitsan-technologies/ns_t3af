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
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Api\EmbeddingResponse;
use NITSAN\NsT3AF\Api\StreamSummary;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Event\ProviderRequestFailedEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatiblePlatform;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiBridgeAdapter;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Default {@see AiServiceInterface} implementation.
 *
 * Resolves a {@see Provider} via {@see ProviderLookupInterface}, fetches the matching
 * {@see AdapterInterface} from {@see AdapterRegistry}, dispatches the lifecycle
 * events, and delegates the actual SDK call to the adapter's
 * {@see AdapterInterface::platform()} object via duck-typed `invoke()` /
 * `stream()` / `embed()` methods.
 *
 * Concrete bridges that don't expose those methods raise
 * {@see AdapterRuntimeException} — listeners on
 * {@see ProviderRequestFailedEvent} can implement a fallback chain.
 *
 * @internal Construct {@see AiServiceInterface} from DI; this class is not
 *           part of the semver-stable surface.
 */
final class AiService implements AiServiceInterface
{
    private const CALL_COMPLETE = 'complete';
    private const CALL_STREAM = 'stream';
    private const CALL_EMBED = 'embed';

    public function __construct(
        private readonly ProviderLookupInterface $providers,
        private readonly AdapterRegistry $adapters,
        private readonly EventDispatcherInterface $events,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly ?ProviderRepositoryInterface $providerRepository = null,
        private readonly ?RequestTelemetryService $telemetry = null,
    ) {}

    public function complete(string $prompt, AiOptions $options = new AiOptions()): AiResponse
    {
        $provider = $this->provider($options->providerIdentifier, $options->pageId);
        $adapter = $this->resolveAdapter($provider);

        $before = new BeforeProviderRequestEvent($provider, $prompt, $options, self::CALL_COMPLETE);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            return new AiResponse(
                content: '',
                modelId: $this->effectiveModel($provider, $before->getOptions(), self::CALL_COMPLETE),
                providerIdentifier: $provider->identifier,
                cached: false,
                raw: ['cancelled' => $before->getCancellationReason()],
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($before->getOptions()),
            );
        }

        $start = (int) (microtime(true) * 1000);
        try {
            $platform = $adapter->platform($provider);
            $invocation = $this->invokePlatform(
                $platform,
                $provider,
                $this->effectiveModel($provider, $before->getOptions()),
                $before->getPrompt(),
                $before->getOptions(),
            );
        } catch (\Throwable $e) {
            $this->telemetry?->logFailure(
                provider: $provider,
                options: $before->getOptions(),
                prompt: $before->getPrompt(),
                requestType: self::CALL_COMPLETE,
                error: $e,
                latencyMs: (int) (microtime(true) * 1000) - $start,
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, self::CALL_COMPLETE));
            if ($e instanceof AdapterRuntimeException) {
                throw $e;
            }
            throw $this->mapRuntimeException($provider, self::CALL_COMPLETE, $e);
        }

        [$tokensInput, $tokensOutput] = $this->extractUsageFromResult($invocation['result']);
        $response = new AiResponse(
            content: $invocation['content'],
            modelId: $this->effectiveModel($provider, $before->getOptions()),
            providerIdentifier: $provider->identifier,
            tokensInput: $tokensInput,
            tokensOutput: $tokensOutput,
            latencyMs: (int) (microtime(true) * 1000) - $start,
            cached: false,
            raw: $invocation['raw'],
            appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($before->getOptions()),
        );

        $after = new AfterProviderResponseEvent(
            $provider,
            $response,
            $before->getOptions(),
            $before->getPrompt(),
        );
        $this->events->dispatch($after);

        $finalResponse = $after->getResponse();
        $this->telemetry?->logCompletion(
            provider: $provider,
            options: $before->getOptions(),
            prompt: $before->getPrompt(),
            response: $finalResponse,
            requestType: self::CALL_COMPLETE,
        );
        $this->providerRepository?->updateStatus($provider->uid, ['last_used_at' => $GLOBALS['EXEC_TIME'] ?? time()]);

        return $finalResponse;
    }

    public function stream(string $prompt, AiOptions $options = new AiOptions()): \Generator
    {
        $provider = $this->provider($options->providerIdentifier, $options->pageId);
        $adapter = $this->resolveAdapter($provider);

        $before = new BeforeProviderRequestEvent($provider, $prompt, $options, self::CALL_STREAM);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            return;
        }

        $start = (int) (microtime(true) * 1000);
        $content = '';
        try {
            $platform = $adapter->platform($provider);
            $modelId = $this->effectiveModel($provider, $before->getOptions());

            if (method_exists($platform, 'stream')) {
                $payloads = $this->openAiCompatibleInvokePayloads($before->getPrompt(), $provider, $before->getOptions());
                foreach ($this->streamViaPlatformStreamMethod($platform, $modelId, $payloads, $adapter) as $delta) {
                    $content .= $delta;
                    yield $delta;
                }
            } elseif (method_exists($platform, 'invoke')) {
                $payloads = $this->symfonyPlatformInvokePayloads($before->getPrompt(), $provider, $modelId, $before->getOptions());
                foreach ($this->streamViaPlatformInvoke($platform, $modelId, $payloads, $adapter) as $delta) {
                    $content .= $delta;
                    yield $delta;
                }
            } else {
                throw new AdapterRuntimeException(sprintf(
                    'Adapter "%s" platform exposes neither stream() nor invoke() for streaming.',
                    $adapter->getType(),
                ));
            }

            // Client disconnect mid-stream: skip telemetry / after-event / budget (CTX-12).
            if (connection_aborted()) {
                return;
            }

            $response = new AiResponse(
                content: $content,
                modelId: $modelId,
                providerIdentifier: $provider->identifier,
                latencyMs: (int) (microtime(true) * 1000) - $start,
                cached: false,
                raw: [],
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($before->getOptions()),
            );
            $after = new AfterProviderResponseEvent(
                $provider,
                $response,
                $before->getOptions(),
                $before->getPrompt(),
            );
            $this->events->dispatch($after);

            $finalResponse = $after->getResponse();
            $this->telemetry?->logCompletion(
                provider: $provider,
                options: $before->getOptions(),
                prompt: $before->getPrompt(),
                response: $finalResponse,
                requestType: self::CALL_STREAM,
            );
            $this->providerRepository?->updateStatus($provider->uid, ['last_used_at' => $GLOBALS['EXEC_TIME'] ?? time()]);

            return new StreamSummary(content: $finalResponse->content);
        } catch (\Throwable $e) {
            $this->telemetry?->logFailure(
                provider: $provider,
                options: $before->getOptions(),
                prompt: $before->getPrompt(),
                requestType: self::CALL_STREAM,
                error: $e,
                latencyMs: (int) (microtime(true) * 1000) - $start,
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, self::CALL_STREAM));
            if ($e instanceof AdapterRuntimeException) {
                throw $e;
            }
            throw $this->mapRuntimeException($provider, self::CALL_STREAM, $e);
        }
    }

    public function embed(string|array $text, AiOptions $options = new AiOptions()): EmbeddingResponse
    {
        $provider = $this->provider($options->providerIdentifier, $options->pageId);
        $adapter = $this->resolveAdapter($provider);

        $promptForEvent = is_array($text) ? implode("\n", $text) : $text;
        $before = new BeforeProviderRequestEvent($provider, $promptForEvent, $options, self::CALL_EMBED);
        $this->events->dispatch($before);
        if ($before->isCancelled()) {
            return new EmbeddingResponse(
                vectors: [],
                modelId: $this->effectiveModel($provider, $before->getOptions(), self::CALL_EMBED),
                providerIdentifier: $provider->identifier,
                raw: [
                    'error' => $before->getCancellationReason() ?? 'AI provider request was cancelled.',
                ],
            );
        }

        $start = (int) (microtime(true) * 1000);
        try {
            if (
                $adapter instanceof SymfonyAiBridgeAdapter
                && Provider::normalizeAdapterType($provider->adapterType) === 'symfony.huggingface'
            ) {
                $invocation = $adapter->embedFeatureExtraction(
                    $provider,
                    $this->effectiveModel($provider, $before->getOptions(), self::CALL_EMBED),
                    $text,
                );
            } else {
                $platform = $adapter->platform($provider);
                $invocation = $this->embedPlatform(
                    $platform,
                    $provider,
                    $this->effectiveModel($provider, $before->getOptions(), self::CALL_EMBED),
                    $text,
                );
            }
        } catch (\Throwable $e) {
            $this->telemetry?->logFailure(
                provider: $provider,
                options: $before->getOptions(),
                prompt: $before->getPrompt(),
                requestType: self::CALL_EMBED,
                error: $e,
                latencyMs: (int) (microtime(true) * 1000) - $start,
            );
            $this->events->dispatch(new ProviderRequestFailedEvent($provider, $e, self::CALL_EMBED));
            if ($e instanceof AdapterRuntimeException) {
                throw $e;
            }
            throw $this->mapRuntimeException($provider, self::CALL_EMBED, $e);
        }

        $response = new EmbeddingResponse(
            vectors: $invocation['vectors'],
            modelId: $this->effectiveModel($provider, $before->getOptions(), self::CALL_EMBED),
            providerIdentifier: $provider->identifier,
            tokensInput: $this->extractEmbeddingTokensInput($invocation['result']),
            latencyMs: (int) (microtime(true) * 1000) - $start,
            raw: $this->extractEmbedRawPayload($invocation['result']),
        );

        if ($response->vectors === []) {
            $apiError = $this->extractEmbedApiErrorMessage($response->raw);
            if ($apiError !== '') {
                throw new AdapterRuntimeException($this->formatEmbeddingProviderError($apiError, $provider));
            }
        }

        $textForTelemetry = is_array($text) ? implode("\n", $text) : $text;
        $this->telemetry?->logEmbedding($provider, $before->getOptions(), $textForTelemetry, $response);
        // Budget/usage listeners bind to AfterProviderResponseEvent; embedding
        // token usage must count against per-user budgets too (CTX-14).
        $this->events->dispatch(new AfterProviderResponseEvent(
            $provider,
            new AiResponse(
                content: '',
                modelId: $response->modelId,
                providerIdentifier: $provider->identifier,
                tokensInput: $response->tokensInput,
                latencyMs: $response->latencyMs,
                raw: ['call' => self::CALL_EMBED],
                appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($before->getOptions()),
            ),
            $before->getOptions(),
            $before->getPrompt(),
        ));
        $this->providerRepository?->updateStatus($provider->uid, ['last_used_at' => $GLOBALS['EXEC_TIME'] ?? time()]);

        return $response;
    }

    public function provider(?string $identifier = null, ?int $pageId = null): Provider
    {
        $storagePid = $this->resolveStoragePid($pageId);
        $provider = $identifier === null
            ? $this->providers->findDefault($storagePid)
            : $this->providers->findByIdentifier($identifier, $storagePid);

        if ($provider === null) {
            throw new UnknownAdapterException($identifier === null
                ? 'No default AI provider is configured.'
                : sprintf('AI provider with identifier "%s" not found.', $identifier));
        }
        if (!$provider->isEnabled) {
            throw new UnknownAdapterException(sprintf(
                'AI provider "%s" is disabled.',
                $provider->identifier,
            ));
        }

        return $provider;
    }

    private function resolveAdapter(Provider $provider): AdapterInterface
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

    private function effectiveModel(Provider $provider, AiOptions $options, string $callType = self::CALL_COMPLETE): string
    {
        if ($options->modelId !== null && $options->modelId !== '') {
            return $options->modelId;
        }
        if ($callType === self::CALL_EMBED && $provider->embeddingModelId !== '') {
            return $provider->embeddingModelId;
        }

        return $provider->modelId;
    }

    /**
     * @param string|list<string> $text
     * @return array{vectors: list<list<float>>, result: mixed}
     */
    private function embedPlatform(object $platform, Provider $provider, string $modelId, string|array $text): array
    {
        $payloads = $this->embeddingPayloads($text);
        $invokeOptions = [];
        if (Provider::normalizeAdapterType($provider->adapterType) === 'symfony.huggingface') {
            $invokeOptions = ['task' => 'feature-extraction'];
        }

        // The built-in OpenAI-compatible platform implements both invoke() (chat)
        // and embed() (embeddings); embeddings must never go through invoke(),
        // which posts to /chat/completions and returns chat content.
        if ($platform instanceof OpenAiCompatiblePlatform) {
            $raw = $platform->embed($modelId, $text);
        } elseif (method_exists($platform, 'invoke')) {
            // Prefer invoke() for Symfony AI Platform (DeferredResult + TokenUsageExtractor metadata).
            $raw = $this->invokeWithPayloadFallbacks(
                $platform,
                'invoke',
                $modelId,
                $payloads,
                $invokeOptions,
            );
        } elseif (method_exists($platform, 'embed')) {
            $raw = $platform->embed($modelId, $text);
        } elseif (method_exists($platform, 'request')) {
            $raw = $this->invokeWithPayloadFallbacks(
                $platform,
                'request',
                $modelId,
                $payloads,
                $invokeOptions,
            );
        } else {
            throw new AdapterRuntimeException(
                'Platform object exposes neither embed(), invoke(), nor request() for embeddings.',
            );
        }

        $this->materializePlatformResult($raw);

        return [
            'vectors' => $this->coerceEmbedVectors($raw),
            'result' => $raw,
        ];
    }

    /**
     * @param string|list<string> $text
     * @return list<array<int|string, mixed>|string>
     */
    private function embeddingPayloads(string|array $text): array
    {
        if (is_array($text)) {
            return [$text, ['input' => $text]];
        }

        return [$text, ['input' => $text], [$text]];
    }

    /**
     * Normalise vendor-specific embedding results to plain vector arrays.
     *
     * @return list<list<float>>
     */
    private function coerceEmbedVectors(mixed $raw): array
    {
        if (is_object($raw) && method_exists($raw, 'asVectors')) {
            try {
                /** @var mixed $vectors */
                $vectors = $raw->asVectors();
                if (is_array($vectors)) {
                    return $this->normaliseVectorObjects($vectors);
                }
            } catch (\Throwable) {
                // Fall through to other extraction strategies.
            }
        }

        if (is_object($raw) && method_exists($raw, 'getContent')) {
            try {
                $content = $raw->getContent();
                if (is_array($content)) {
                    return $this->normaliseVectorObjects($content);
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        if (is_array($raw) && isset($raw['data']) && is_array($raw['data'])) {
            $vectors = [];
            foreach ($raw['data'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $embedding = $item['embedding'] ?? null;
                if (is_array($embedding)) {
                    $row = [];
                    foreach ($embedding as $value) {
                        $row[] = is_numeric($value) ? (float) $value : 0.0;
                    }
                    $vectors[] = $row;
                }
            }
            if ($vectors !== []) {
                return $vectors;
            }
        }

        return $this->normaliseVectors($raw);
    }

    /**
     * @param array<mixed, mixed> $vectors
     * @return list<list<float>>
     */
    private function normaliseVectorObjects(array $vectors): array
    {
        $out = [];
        foreach ($vectors as $vector) {
            if (is_object($vector) && method_exists($vector, 'getData')) {
                try {
                    $data = $vector->getData();
                    if (is_array($data)) {
                        $row = [];
                        foreach ($data as $value) {
                            $row[] = is_numeric($value) ? (float) $value : 0.0;
                        }
                        $out[] = $row;
                    }
                } catch (\Throwable) {
                    continue;
                }
                continue;
            }
            if (is_iterable($vector)) {
                $row = [];
                foreach ($vector as $float) {
                    $row[] = is_numeric($float) ? (float) $float : 0.0;
                }
                if ($row !== []) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * Invoke the SDK platform's completion method and coerce the result to a
     * plain string. Tolerates the common shapes seen in Symfony AI Platform
     * responses (`getContent()`, `__toString()`, plain array/string).
     *
     * @throws AdapterRuntimeException When the platform exposes no usable invoker.
     */
    /**
     * @return array{content:string,result:mixed,raw:array<string,mixed>}
     */
    private function invokePlatform(object $platform, Provider $provider, string $modelId, string $prompt, AiOptions $options): array
    {
        $payloads = $platform instanceof OpenAiCompatiblePlatform
            ? $this->openAiCompatibleInvokePayloads($prompt, $provider, $options)
            : $this->symfonyPlatformInvokePayloads($prompt, $provider, $modelId, $options);

        if (method_exists($platform, 'invoke')) {
            $result = $this->invokeWithPayloadFallbacks($platform, 'invoke', $modelId, $payloads);
        } elseif (method_exists($platform, 'request')) {
            $result = $this->invokeWithPayloadFallbacks($platform, 'request', $modelId, $payloads);
        } else {
            throw new AdapterRuntimeException('Platform object exposes neither invoke() nor request().');
        }

        $content = '';
        $raw = [];
        if (is_string($result)) {
            $content = $result;
        }
        if (is_object($result)) {
            if (method_exists($result, 'asText')) {
                try {
                    /** @var mixed $text */
                    $text = $result->asText();
                    if (is_string($text)) {
                        $content = $text;
                    }
                } catch (\Throwable) {
                    // Fall through to other extraction strategies.
                }
            }
            if (method_exists($result, 'getResult')) {
                try {
                    /** @var mixed $nestedResult */
                    $nestedResult = $result->getResult();
                    if (is_object($nestedResult) && method_exists($nestedResult, 'getContent')) {
                        $nestedContent = $nestedResult->getContent();
                        if (is_string($nestedContent)) {
                            $content = $nestedContent;
                        }
                    }
                } catch (\Throwable) {
                    // Fall through to other extraction strategies.
                }
            }
            if (method_exists($result, 'getContent')) {
                $content = (string) $result->getContent();
            }
            if (method_exists($result, '__toString')) {
                $content = (string) $result;
            }
            $raw = $this->extractRawResponse($result);
        }
        if (is_array($result) && isset($result['content'])) {
            $content = (string) $result['content'];
            $raw = $result;
        }

        return [
            'content' => $content,
            'result' => $result,
            'raw' => $raw,
        ];
    }

    /**
     * Payload order for {@see OpenAiCompatiblePlatform} (messages/input shapes).
     *
     * @return list<array<string, mixed>|string>
     */
    private function openAiCompatibleInvokePayloads(string $prompt, Provider $provider, AiOptions $options): array
    {
        $payloads = [
            ['messages' => $this->resolveChatMessages($prompt, $provider, $options)],
            $prompt,
            ['input' => $prompt],
        ];

        return $payloads;
    }

    /**
     * Payload order for Symfony AI Platform bridges (Ollama, OpenAI, …).
     *
     * Ollama {@code /api/chat} requires {@code model} + {@code messages}; {@code input} alone yields HTTP 400.
     * A plain string is converted to {@see MessageBag} by Symfony and includes the model name.
     * Vision (array content) uses MessageBag Text+ImageUrl; Symfony OpenAI maps that to Responses {@code input}.
     * Fallback order is unchanged so non-vision callers (Ollama, text chat) behave as before.
     *
     * @return list<array<int|string, mixed>|string|object>
     */
    private function symfonyPlatformInvokePayloads(string $prompt, Provider $provider, string $modelId, AiOptions $options): array
    {
        $payloads = [];

        $messages = $this->resolveChatMessages($prompt, $provider, $options);
        $messageBag = $this->createMessageBagFromMessages($messages);
        if ($messageBag !== null) {
            $payloads[] = $messageBag;
        }

        $payloads[] = $prompt;
        if ($modelId !== '') {
            $payloads[] = [
                'model' => $modelId,
                'messages' => $messages,
            ];
        }

        $payloads[] = ['messages' => $messages];
        $payloads[] = ['input' => $prompt];

        return $payloads;
    }

    /**
     * Prefer multi-turn messages from {@see AiOptions::$extra} when present (chat/RAG).
     *
     * @return list<array{role: string, content: string|list<array<string,mixed>>}>
     */
    private function resolveChatMessages(string $prompt, Provider $provider, AiOptions $options): array
    {
        $fromExtra = $this->messagesFromOptionsExtra($options);
        if ($fromExtra !== []) {
            return $fromExtra;
        }

        return $this->chatMessagesArray($prompt, $provider, $options);
    }

    /**
     * @return list<array{role: string, content: string|list<array<string,mixed>>}>
     */
    private function messagesFromOptionsExtra(AiOptions $options): array
    {
        $extra = $options->extra;
        $raw = $extra['messages'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $messages = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $content = $row['content'] ?? null;
            if ($content === null || $content === '' || $content === []) {
                continue;
            }
            $role = trim((string) ($row['role'] ?? 'user'));
            if ($role === 'model') {
                $role = 'assistant';
            }
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        return $messages;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function chatMessagesArray(string $prompt, Provider $provider, AiOptions $options): array
    {
        $messages = [];
        $system = trim($options->systemPrompt ?? $provider->systemPrompt);
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }

    /**
     * Build a Symfony AI MessageBag.
     *
     * String content (all existing text callers) is unchanged.
     * Array content (vision: text + image_url blocks) becomes Text + ImageUrl parts so the
     * OpenAI Responses bridge serializes under {@code input} instead of rejected {@code messages}.
     *
     * @param list<array{role: string, content: string|list<array<string,mixed>>}> $messages
     */
    private function createMessageBagFromMessages(array $messages): ?object
    {
        // Resolve the Symfony AI message classes by their real FQN: un-scoped in
        // Composer mode, php-scoper-prefixed (NITSAN\T3af\Vendor\…) when they
        // come from the bundled t3af.phar. Referencing only the un-scoped name
        // here makes class_exists() false in classic mode, so the MessageBag is never
        // built — the platform then receives a raw ['messages' => …] payload, which the
        // OpenAI bridge rejects ("'messages' moved to 'input'" on /v1/responses).
        $messageClass = $this->resolveSymfonyAiClass('Symfony\\AI\\Platform\\Message\\Message');
        $bagClass = $this->resolveSymfonyAiClass('Symfony\\AI\\Platform\\Message\\MessageBag');
        $textClass = $this->resolveSymfonyAiClass('Symfony\\AI\\Platform\\Message\\Content\\Text');
        $imageUrlClass = $this->resolveSymfonyAiClass('Symfony\\AI\\Platform\\Message\\Content\\ImageUrl');
        if ($messageClass === null || $bagClass === null) {
            return null;
        }
        if ($messages === []) {
            return null;
        }

        $bagMessages = [];
        foreach ($messages as $row) {
            $role = (string) ($row['role'] ?? 'user');
            $content = $row['content'] ?? null;

            // Vision / multimodal only: array content blocks (text + image_url).
            // Text-only string content keeps the historical path below.
            if (is_array($content)) {
                $parts = $this->contentPartsFromVisionBlocks($content, $textClass, $imageUrlClass);
                if ($parts === []) {
                    continue;
                }
                if ($role === 'system' || $role === 'assistant') {
                    // System/assistant messages stay text-only even if blocks were passed.
                    $textOnly = $this->textFromContentParts($parts, $textClass);
                    if ($textOnly === '') {
                        continue;
                    }
                    $bagMessages[] = $role === 'system'
                        ? $messageClass::forSystem($textOnly)
                        : $messageClass::ofAssistant($textOnly);
                    continue;
                }
                $bagMessages[] = $messageClass::ofUser(...$parts);
                continue;
            }

            $content = trim((string) ($content ?? ''));
            if ($content === '') {
                continue;
            }
            $bagMessages[] = match ($role) {
                'system' => $messageClass::forSystem($content),
                'assistant' => $messageClass::ofAssistant($content),
                default => $messageClass::ofUser($content),
            };
        }
        if ($bagMessages === []) {
            return null;
        }

        return new $bagClass(...$bagMessages);
    }

    /**
     * Map Chat Completions vision content blocks to Symfony AI content objects.
     * Only used when message content is an array (vision callers).
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<object>
     */
    private function contentPartsFromVisionBlocks(array $blocks, ?string $textClass, ?string $imageUrlClass): array
    {
        $parts = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'text' || $type === 'input_text') {
                $text = trim((string) ($block['text'] ?? ''));
                if ($text === '' || $textClass === null) {
                    continue;
                }
                $parts[] = new $textClass($text);
                continue;
            }
            if ($type === 'image_url' || $type === 'input_image') {
                $url = $this->imageUrlFromVisionBlock($block);
                if ($url === '' || $imageUrlClass === null) {
                    continue;
                }
                $parts[] = new $imageUrlClass($url);
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function imageUrlFromVisionBlock(array $block): string
    {
        $imageUrl = $block['image_url'] ?? null;
        if (is_array($imageUrl)) {
            return trim((string) ($imageUrl['url'] ?? ''));
        }
        if (is_string($imageUrl) && $imageUrl !== '') {
            return trim($imageUrl);
        }
        if (isset($block['url']) && is_string($block['url'])) {
            return trim($block['url']);
        }

        return '';
    }

    /**
     * @param list<object> $parts
     */
    private function textFromContentParts(array $parts, ?string $textClass): string
    {
        if ($textClass === null) {
            return '';
        }
        $chunks = [];
        foreach ($parts as $part) {
            if (!is_a($part, $textClass) || !method_exists($part, 'getText')) {
                continue;
            }
            $text = trim((string) $part->getText());
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * Resolve a Symfony AI class to whichever FQN actually exists at runtime:
     * the original (Composer mode) or the phar-scoped variant (classic mode).
     *
     * @param class-string|string $fqcn un-scoped Symfony AI FQN
     * @return ?class-string
     */
    private function resolveSymfonyAiClass(string $fqcn): ?string
    {
        if (class_exists($fqcn)) {
            return $fqcn;
        }
        $scoped = 'NITSAN\T3af\\Vendor\\' . $fqcn;

        return class_exists($scoped) ? $scoped : null;
    }

    /**
     * OpenAI-compatible adapters expose stream() directly on the platform object.
     *
     * @param list<array<int|string, mixed>|object|string> $payloads
     */
    private function streamViaPlatformStreamMethod(
        object $platform,
        string $modelId,
        array $payloads,
        AdapterInterface $adapter,
    ): \Generator {
        /** @var iterable<mixed>|mixed $iter */
        $iter = $this->invokeWithPayloadFallbacks($platform, 'stream', $modelId, $payloads);
        if (!is_iterable($iter)) {
            throw new AdapterRuntimeException(sprintf(
                'Adapter "%s" stream() did not return an iterable result.',
                $adapter->getType(),
            ));
        }

        foreach ($iter as $chunk) {
            yield $this->coerceStreamChunk($chunk);
        }
    }

    /**
     * Symfony AI Platform bridges stream via invoke(..., ['stream' => true]) and
     * DeferredResult::asTextStream() / asStream() — not platform->stream().
     *
     * @param list<array<int|string, mixed>|object|string> $payloads
     */
    private function streamViaPlatformInvoke(
        object $platform,
        string $modelId,
        array $payloads,
        AdapterInterface $adapter,
    ): \Generator {
        $invoke = [$platform, 'invoke'];
        if (!is_callable($invoke)) {
            throw new AdapterRuntimeException(sprintf(
                'Adapter "%s" platform invoke method is not callable.',
                $adapter->getType(),
            ));
        }

        $lastException = null;
        foreach ($payloads as $payload) {
            try {
                $result = $invoke($modelId, $payload, ['stream' => true]);
                yield from $this->yieldFromStreamResult($result, $adapter);
                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                if (!$this->isPayloadShapeError($e)) {
                    throw $e;
                }
            }
        }

        throw $lastException ?? new AdapterRuntimeException(sprintf(
            'Adapter "%s" invoke(stream) did not return a streamable result.',
            $adapter->getType(),
        ));
    }

    /**
     * @throws AdapterRuntimeException
     */
    private function yieldFromStreamResult(mixed $result, AdapterInterface $adapter): \Generator
    {
        if (is_object($result) && method_exists($result, 'asTextStream')) {
            foreach ($result->asTextStream() as $delta) {
                yield $this->coerceStreamChunk($delta);
            }

            return;
        }

        if (is_object($result) && method_exists($result, 'asStream')) {
            foreach ($result->asStream() as $delta) {
                yield $this->coerceStreamChunk($delta);
            }

            return;
        }

        if (is_iterable($result)) {
            foreach ($result as $chunk) {
                yield $this->coerceStreamChunk($chunk);
            }

            return;
        }

        throw new AdapterRuntimeException(sprintf(
            'Adapter "%s" invoke(stream) did not return a streamable result.',
            $adapter->getType(),
        ));
    }

    private function coerceStreamChunk(mixed $chunk): string
    {
        if (is_string($chunk)) {
            return $chunk;
        }
        if (is_object($chunk) && method_exists($chunk, 'getText')) {
            return (string) $chunk->getText();
        }
        if (is_object($chunk) && method_exists($chunk, '__toString')) {
            return (string) $chunk;
        }

        return (string) $chunk;
    }

    /**
     * @param list<array<int|string, mixed>|object|string> $payloads
     * @param array<string, mixed> $invokeOptions
     */
    private function invokeWithPayloadFallbacks(
        object $platform,
        string $method,
        string $modelId,
        array $payloads,
        array $invokeOptions = [],
    ): mixed {
        $lastException = null;
        foreach ($payloads as $payload) {
            try {
                return $platform->{$method}($modelId, $payload, $invokeOptions);
            } catch (\Throwable $e) {
                $lastException = $e;
                if (!$this->isPayloadShapeError($e)) {
                    throw $e;
                }
            }
        }

        throw $lastException ?? new AdapterRuntimeException('Unable to invoke platform method.');
    }

    private function isPayloadShapeError(\Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'payload must be an array')
            || str_contains($message, 'must be of type array')
            || str_contains($message, 'string given')
            || str_contains($message, 'array given')
            || str_contains($message, 'could not normalize object of type')
            || str_contains($message, 'no supporting normalizer found');
    }

    private function mapRuntimeException(Provider $provider, string $callType, \Throwable $error): AdapterRuntimeException
    {
        $status = $this->extractHttpStatusCode($error->getMessage());
        if ($status === 0) {
            return new AdapterRuntimeException($error->getMessage(), 0, $error);
        }

        $prefix = match ($status) {
            400 => 'Provider rejected request payload.',
            401, 403 => 'Provider authentication/authorization failed.',
            404 => 'Provider endpoint or model route not found.',
            408 => 'Provider request timed out.',
            429 => 'Rate limit or quota exceeded at provider.',
            500, 502, 503, 504 => 'Provider temporary server error.',
            default => 'Provider runtime error.',
        };

        return new AdapterRuntimeException(
            sprintf('%s [provider=%s call=%s] %s', $prefix, $provider->identifier, $callType, $error->getMessage()),
            $status,
            $error,
        );
    }

    private function extractHttpStatusCode(string $message): int
    {
        if (preg_match('/HTTP\\/\\d(?:\\.\\d)?\\s+(\\d{3})/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function extractUsageFromResult(mixed $result): array
    {
        if (is_object($result)) {
            $this->materializePlatformResult($result);

            if (method_exists($result, 'getMetadata')) {
                try {
                    $metadata = $result->getMetadata();
                    if (is_object($metadata) && method_exists($metadata, 'get')) {
                        $tokenUsage = $metadata->get('token_usage');
                        if (is_object($tokenUsage)) {
                            return $this->extractTokenUsagePair($tokenUsage);
                        }
                    }
                } catch (\Throwable) {
                    // Continue to other extraction strategies.
                }
            }

            if (method_exists($result, 'getResult')) {
                try {
                    /** @var mixed $converted */
                    $converted = $result->getResult();
                    if (is_object($converted) && method_exists($converted, 'getMetadata')) {
                        /** @var mixed $metadata */
                        $metadata = $converted->getMetadata();
                        if (is_object($metadata) && method_exists($metadata, 'get')) {
                            /** @var mixed $tokenUsage */
                            $tokenUsage = $metadata->get('token_usage');
                            if (is_object($tokenUsage)) {
                                return $this->extractTokenUsagePair($tokenUsage);
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Continue to other extraction strategies.
                }
            }

            $viaConverter = $this->extractUsageViaResultConverter($result);
            if ($viaConverter !== [0, 0]) {
                return $viaConverter;
            }

            $raw = $this->extractRawResponse($result);
            $usage = $raw['usage'] ?? null;
            if (is_array($usage)) {
                return $this->extractUsageFromUsageArray($usage);
            }
        }

        if (is_array($result)) {
            $usage = $result['usage'] ?? null;
            if (is_array($usage)) {
                return $this->extractUsageFromUsageArray($usage);
            }
        }

        return [0, 0];
    }

    private function materializePlatformResult(mixed $result): void
    {
        if (!is_object($result)) {
            return;
        }

        if (method_exists($result, 'getResult')) {
            try {
                $result->getResult();

                return;
            } catch (\Throwable) {
                // Fall through to asVectors().
            }
        }

        if (method_exists($result, 'asVectors')) {
            try {
                $result->asVectors();
            } catch (\Throwable) {
                // Best effort only.
            }
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private function extractUsageViaResultConverter(object $result): array
    {
        if (!method_exists($result, 'getResultConverter') || !method_exists($result, 'getRawResult')) {
            return [0, 0];
        }

        try {
            $converter = $result->getResultConverter();
            if (!is_object($converter) || !method_exists($converter, 'getTokenUsageExtractor')) {
                return [0, 0];
            }
            $extractor = $converter->getTokenUsageExtractor();
            if (!is_object($extractor) || !method_exists($extractor, 'extract')) {
                return [0, 0];
            }

            /** @var mixed $rawResult */
            $rawResult = $result->getRawResult();
            if (!is_object($rawResult)) {
                return [0, 0];
            }

            /** @var mixed $tokenUsage */
            $tokenUsage = $extractor->extract($rawResult, []);
            if (!is_object($tokenUsage)) {
                return [0, 0];
            }

            return $this->extractTokenUsagePair($tokenUsage);
        } catch (\Throwable) {
            return [0, 0];
        }
    }

    private function extractEmbeddingTokensInput(mixed $result): int
    {
        [$prompt, $completion] = $this->extractUsageFromResult($result);
        if ($prompt > 0) {
            return $prompt;
        }

        return max(0, $prompt + $completion);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEmbedRawPayload(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }
        if (is_object($result)) {
            $payload = $this->extractRawResponse($result);
            if ($payload !== []) {
                return $payload;
            }

            [$prompt, $completion] = $this->extractUsageFromResult($result);
            if ($prompt > 0 || $completion > 0) {
                return [
                    'usage' => [
                        'prompt_tokens' => $prompt,
                        'completion_tokens' => $completion,
                        'total_tokens' => $prompt + $completion,
                    ],
                ];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function extractEmbedApiErrorMessage(array $raw): string
    {
        $error = $raw['error'] ?? '';
        if (is_string($error) && trim($error) !== '') {
            return trim($error);
        }
        if (is_array($error)) {
            $message = $error['message'] ?? $error[0] ?? '';
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        return '';
    }

    private function formatEmbeddingProviderError(string $message, Provider $provider): string
    {
        if (Provider::normalizeAdapterType($provider->adapterType) !== 'symfony.huggingface') {
            return $message;
        }
        if (stripos($message, 'inference providers') === false) {
            return $message;
        }

        return $message . ' Create a fine-grained Hugging Face token with permission "Make calls to Inference Providers" at https://huggingface.co/settings/tokens, then update the provider API key in AI Foundation → Providers.';
    }

    /**
     * @return array{0:int,1:int}
     */
    private function extractTokenUsagePair(object $tokenUsage): array
    {
        $prompt = method_exists($tokenUsage, 'getPromptTokens') ? $tokenUsage->getPromptTokens() : null;
        $completion = method_exists($tokenUsage, 'getCompletionTokens') ? $tokenUsage->getCompletionTokens() : null;
        $promptInt = $prompt !== null ? (int) $prompt : 0;
        $completionInt = $completion !== null ? (int) $completion : 0;
        if ($promptInt === 0 && $completionInt === 0 && method_exists($tokenUsage, 'getTotalTokens')) {
            $total = $tokenUsage->getTotalTokens();
            if ($total !== null && (int) $total > 0) {
                return [(int) $total, 0];
            }
        }

        return [max(0, $promptInt), max(0, $completionInt)];
    }

    /**
     * @param array<string, mixed> $usage
     * @return array{0:int,1:int}
     */
    private function extractUsageFromUsageArray(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        if ($prompt === 0 && $completion === 0 && isset($usage['total_tokens'])) {
            $prompt = (int) $usage['total_tokens'];
        }

        return [max(0, $prompt), max(0, $completion)];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawResponse(object $result): array
    {
        foreach ($this->resolveRawResultCandidates($result) as $rawResult) {
            if (!is_object($rawResult)) {
                continue;
            }

            if (method_exists($rawResult, 'getData')) {
                try {
                    /** @var mixed $data */
                    $data = $rawResult->getData();
                    if (is_array($data) && $data !== []) {
                        return $data;
                    }
                } catch (\Throwable) {
                    // Try HTTP body fallback below.
                }
            }

            if (method_exists($rawResult, 'getObject')) {
                try {
                    /** @var mixed $object */
                    $object = $rawResult->getObject();
                    if (is_object($object) && method_exists($object, 'getContent')) {
                        /** @var mixed $body */
                        $body = $object->getContent(false);
                        if (is_string($body) && $body !== '') {
                            /** @var mixed $decoded */
                            $decoded = json_decode($body, true);
                            if (is_array($decoded) && $decoded !== []) {
                                return $decoded;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Try next candidate.
                }
            }
        }

        return [];
    }

    /**
     * @return list<mixed>
     */
    private function resolveRawResultCandidates(object $result): array
    {
        $candidates = [];

        if (method_exists($result, 'getRawResult')) {
            try {
                $candidates[] = $result->getRawResult();
            } catch (\Throwable) {
                // Continue.
            }
        }

        if (method_exists($result, 'getResult')) {
            try {
                /** @var mixed $converted */
                $converted = $result->getResult();
                if (is_object($converted) && method_exists($converted, 'getRawResult')) {
                    $candidates[] = $converted->getRawResult();
                }
            } catch (\Throwable) {
                // Continue.
            }
        }

        return $candidates;
    }

    /**
     * @return list<list<float>>
     */
    private function normaliseVectors(mixed $raw): array
    {
        if (!is_iterable($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $vector) {
            if (!is_iterable($vector)) {
                continue;
            }
            $row = [];
            foreach ($vector as $float) {
                $row[] = is_numeric($float) ? (float) $float : 0.0;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function resolveStoragePid(?int $pageId): ?int
    {
        if ($pageId !== null && $pageId > 0) {
            return $this->siteStorageContext->resolveStoragePidFromPageId($pageId);
        }

        return null;
    }
}
