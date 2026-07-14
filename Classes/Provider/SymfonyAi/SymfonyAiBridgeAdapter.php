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

namespace NITSAN\NsT3AF\Provider\SymfonyAi;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Service\CredentialCipher;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Generic adapter wrapping any Symfony AI Platform bridge.
 *
 * One instance is registered per discovered `symfony/ai-*-platform` (or
 * `lochmueller/seal-ai-*` re-export) package — see
 * {@see \NITSAN\NsT3AF\DependencyInjection\AdapterCompilerPass}.
 *
 * Symfony AI is a soft dependency: when the runtime classes are absent,
 * {@see testConnection()} returns a {@see VerifyResult::failure()} with a
 * descriptive message, and {@see platform()} throws
 * {@see AdapterRuntimeException}. Detection is `class_exists`-based to avoid
 * a hard composer `require`.
 *
 * @internal
 */
final class SymfonyAiBridgeAdapter implements AdapterInterface
{
    private const HUGGINGFACE_INFERENCE_URL = 'https://router.huggingface.co/hf-inference/models/';

    private const DEFAULT_HUGGINGFACE_EMBEDDING_MODEL = 'sentence-transformers/all-MiniLM-L6-v2';

    /**
     * Per-vendor probe configuration for {@see testConnection()}.
     *
     * Each entry describes a cheap, idempotent HTTP endpoint that requires
     * valid credentials — typically a `/models`-style listing — so a real
     * authentication failure is observable as 401/403.
     *
     * @var array<string, array{path: string, auth: 'bearer'|'x-api-key'|'query-key'|'none', extraHeaders?: array<string, string>}>
     */
    private const PROBE_CONFIG = [
        'symfony.openai' => ['path' => '/models', 'auth' => 'bearer'],
        'symfony.anthropic' => [
            'path' => '/models',
            'auth' => 'x-api-key',
            'extraHeaders' => ['anthropic-version' => '2023-06-01'],
        ],
        'symfony.gemini' => ['path' => '/models', 'auth' => 'query-key'],
        'symfony.mistral' => ['path' => '/models', 'auth' => 'bearer'],
        'symfony.ollama' => ['path' => '/api/tags', 'auth' => 'none'],
        'symfony.openrouter' => ['path' => '/models', 'auth' => 'bearer'],
    ];

    public function __construct(
        private readonly BridgeDescriptor $descriptor,
        private readonly CredentialCipher $cipher,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function getType(): string
    {
        return $this->canonicalTypeKey($this->descriptor->type);
    }

    public function getDisplayName(): string
    {
        return $this->descriptor->displayName;
    }

    public function getDefaultEndpoint(): string
    {
        return $this->descriptor->defaultEndpoint;
    }

    public function getDefaultCapabilities(): array
    {
        return $this->descriptor->defaultCapabilities;
    }

    public function testConnection(Provider $provider): VerifyResult
    {
        $start = (int) (microtime(true) * 1000);

        $config = self::PROBE_CONFIG[$this->canonicalTypeKey($this->descriptor->type)] ?? null;
        if ($config === null) {
            if ($this->canonicalTypeKey($this->descriptor->type) === 'symfony.huggingface') {
                return $this->probeHuggingFaceEmbeddings($provider, $start);
            }

            return $this->fallbackProbe($provider, $start);
        }

        $endpoint = trim($provider->endpointUrl);
        if ($endpoint === '') {
            $endpoint = trim($this->descriptor->defaultEndpoint);
        }
        if ($endpoint === '') {
            return VerifyResult::failure('Endpoint URL is required.', $this->elapsed($start));
        }

        try {
            $apiKey = $this->resolveApiKey($provider);
        } catch (AdapterRuntimeException $e) {
            return VerifyResult::failure($e->getMessage(), $this->elapsed($start));
        }

        if ($config['auth'] !== 'none' && $apiKey === '') {
            return VerifyResult::failure('API key is required.', $this->elapsed($start));
        }

        $url = rtrim($endpoint, '/') . $config['path'];
        $headers = ['User-Agent' => 'ns_t3af/2.0', 'Accept' => 'application/json'];

        switch ($config['auth']) {
            case 'bearer':
                $headers['Authorization'] = 'Bearer ' . $apiKey;
                break;
            case 'x-api-key':
                $headers['x-api-key'] = $apiKey;
                break;
            case 'query-key':
                $url .= (str_contains($url, '?') ? '&' : '?') . 'key=' . urlencode($apiKey);
                break;
        }
        if (isset($config['extraHeaders'])) {
            $headers = array_merge($headers, $config['extraHeaders']);
        }

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => $headers,
                'timeout' => 5,
                'connect_timeout' => 3,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return VerifyResult::failure(
                sprintf('Cannot reach %s: %s', parse_url($url, PHP_URL_HOST) ?: $url, $e->getMessage()),
                $this->elapsed($start),
            );
        }

        $status = $response->getStatusCode();
        $latency = $this->elapsed($start);
        $body = (string) $response->getBody();

        if ($status >= 200 && $status < 300) {
            return VerifyResult::ok(
                sprintf('Connected (HTTP %d)', $status),
                $this->extractModelsFromJson($body),
                $latency,
            );
        }
        if ($status === 401 || $status === 403) {
            return VerifyResult::failure(sprintf('Invalid credentials (HTTP %d).', $status), $latency);
        }
        if ($status === 404) {
            return VerifyResult::failure(
                sprintf('Endpoint not found (HTTP %d). Check that "%s" is correct.', $status, $endpoint),
                $latency,
            );
        }

        $errorMessage = $this->extractApiError($body) ?? substr($body, 0, 200);

        return VerifyResult::failure(sprintf('HTTP %d: %s', $status, $errorMessage), $latency);
    }

    /**
     * Best-effort probe for adapter types we have no curated `/models` endpoint
     * for. Falls back to constructing the platform object — same loose check
     * the previous implementation did, but explicitly labelled "not validated".
     */
    private function fallbackProbe(Provider $provider, int $start): VerifyResult
    {
        try {
            $this->platform($provider);

            return VerifyResult::ok(
                'Platform constructed; credentials NOT validated for this adapter type.',
                [],
                $this->elapsed($start),
            );
        } catch (\Throwable $e) {
            return VerifyResult::failure($e->getMessage(), $this->elapsed($start));
        }
    }

    private function probeHuggingFaceEmbeddings(Provider $provider, int $start): VerifyResult
    {
        $modelId = $this->resolveHuggingFaceEmbeddingModelId($provider);

        try {
            $http = $this->requestHuggingFaceFeatureExtraction($provider, $modelId, 'connection test');
        } catch (AdapterRuntimeException $e) {
            return VerifyResult::failure($e->getMessage(), $this->elapsed($start));
        } catch (\Throwable $e) {
            return VerifyResult::failure(
                sprintf('Cannot reach Hugging Face Inference API: %s', $e->getMessage()),
                $this->elapsed($start),
            );
        }

        $latency = $this->elapsed($start);

        if ($http['status'] >= 200 && $http['status'] < 300) {
            $decoded = is_array($http['decoded']) ? $http['decoded'] : [];
            $vectors = $this->parseHuggingFaceEmbeddingVectors($decoded);
            if ($vectors === []) {
                $preview = trim($http['body']) !== '' ? substr(trim($http['body']), 0, 300) : 'empty response body';

                return VerifyResult::failure(
                    'Embedding probe returned HTTP 200 but no usable vectors. Response preview: ' . $preview,
                    $latency,
                );
            }

            return VerifyResult::ok(
                sprintf('Embedding probe OK (HTTP %d, model %s)', $http['status'], $modelId),
                [$modelId],
                $latency,
            );
        }

        $errorMessage = $this->extractApiError($http['body']) ?? substr($http['body'], 0, 300);
        if ($http['status'] === 401 || $http['status'] === 403) {
            return VerifyResult::failure(
                $this->formatHuggingFaceInferenceProvidersError($errorMessage),
                $latency,
            );
        }

        return VerifyResult::failure(
            sprintf('Embedding probe failed (HTTP %d): %s', $http['status'], $errorMessage),
            $latency,
        );
    }

    /**
     * HF router requires an explicit /pipeline/feature-extraction path; Symfony ModelClient does not.
     *
     * @return array{vectors: list<list<float>>, result: mixed}
     */
    public function embedFeatureExtraction(Provider $provider, string $modelId, string|array $text): array
    {
        $modelId = $this->resolveHuggingFaceEmbeddingModelId($provider, $modelId);
        $http = $this->requestHuggingFaceFeatureExtraction($provider, $modelId, $text);

        if ($http['status'] < 200 || $http['status'] >= 300) {
            $errorMessage = $this->extractApiError($http['body']) ?? substr($http['body'], 0, 300);
            if ($http['status'] === 401 || $http['status'] === 403) {
                throw new AdapterRuntimeException($this->formatHuggingFaceInferenceProvidersError($errorMessage));
            }

            throw new AdapterRuntimeException(
                $errorMessage !== ''
                    ? $errorMessage
                    : sprintf('Hugging Face embedding failed (HTTP %d).', $http['status']),
            );
        }

        $decoded = is_array($http['decoded']) ? $http['decoded'] : [];
        $vectors = $this->parseHuggingFaceEmbeddingVectors($decoded);
        if ($vectors === []) {
            $preview = trim($http['body']) !== '' ? substr(trim($http['body']), 0, 300) : 'empty response body';
            throw new AdapterRuntimeException(
                'Hugging Face embedding returned no usable vectors. Response preview: ' . $preview,
            );
        }

        return [
            'vectors' => $vectors,
            'result' => $decoded,
        ];
    }

    private function resolveHuggingFaceEmbeddingModelId(Provider $provider, string $modelId = ''): string
    {
        $modelId = trim($modelId);
        if ($modelId !== '') {
            return $modelId;
        }

        $embeddingModelId = trim($provider->embeddingModelId);
        if ($embeddingModelId !== '') {
            return $embeddingModelId;
        }

        return self::DEFAULT_HUGGINGFACE_EMBEDDING_MODEL;
    }

    private function huggingFaceFeatureExtractionUrl(string $modelId): string
    {
        return self::HUGGINGFACE_INFERENCE_URL . $modelId . '/pipeline/feature-extraction';
    }

    /**
     * @return array{status: int, body: string, decoded: mixed}
     */
    private function requestHuggingFaceFeatureExtraction(Provider $provider, string $modelId, string|array $inputs): array
    {
        try {
            $apiKey = $this->resolveApiKey($provider);
        } catch (AdapterRuntimeException $e) {
            throw $e;
        }

        if ($apiKey === '') {
            throw new AdapterRuntimeException('API key is required.');
        }

        $response = $this->requestFactory->request(
            $this->huggingFaceFeatureExtractionUrl($modelId),
            'POST',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'X-Wait-For-Model' => 'true',
                ],
                'json' => ['inputs' => $inputs],
                'timeout' => 60,
                'connect_timeout' => 5,
                'http_errors' => false,
            ],
        );

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
            'decoded' => $decoded,
        ];
    }

    /**
     * @return list<list<float>>
     */
    private function parseHuggingFaceEmbeddingVectors(array $responseData): array
    {
        if ($responseData === []) {
            return [];
        }

        foreach (['embeddings', 'embedding', 'data', 'vectors'] as $key) {
            if (isset($responseData[$key]) && is_array($responseData[$key])) {
                return $this->parseHuggingFaceEmbeddingVectors($responseData[$key]);
            }
        }

        // Single flat vector: [0.1, 0.2, ...] (HF feature-extraction pipeline for one input).
        if (is_numeric($responseData[0] ?? null)) {
            return [array_map('floatval', $responseData)];
        }

        $first = $responseData[0] ?? null;
        if (!is_array($first)) {
            return [];
        }

        // Sentence-level batch: [[float,...], ...]
        if (is_numeric($first[0] ?? null)) {
            $vectors = [];
            foreach ($responseData as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $vectors[] = array_map('floatval', $row);
            }

            return $vectors;
        }

        // Token-level: mean-pool each sentence.
        $vectors = [];
        foreach ($responseData as $sentenceTokens) {
            if (!is_array($sentenceTokens) || $sentenceTokens === []) {
                continue;
            }
            $vector = $this->meanPoolHuggingFaceTokenEmbeddings($sentenceTokens);
            if ($vector !== []) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    /**
     * @return list<float>
     */
    private function meanPoolHuggingFaceTokenEmbeddings(array $tokenEmbeddings): array
    {
        $tokenCount = count($tokenEmbeddings);
        if ($tokenCount === 0) {
            return [];
        }

        $firstToken = $tokenEmbeddings[0];
        if (!is_array($firstToken)) {
            return [];
        }

        $dims = count($firstToken);
        $averaged = array_fill(0, $dims, 0.0);
        foreach ($tokenEmbeddings as $tokenEmb) {
            if (!is_array($tokenEmb)) {
                continue;
            }
            foreach ($tokenEmb as $i => $val) {
                $averaged[$i] += (float) $val;
            }
        }

        return array_map(static fn(float $value): float => $value / $tokenCount, $averaged);
    }

    private function formatHuggingFaceInferenceProvidersError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            $message = 'Hugging Face rejected the API key.';
        }
        if (stripos($message, 'inference providers') === false) {
            return $message;
        }

        return $message . ' Create a fine-grained token with permission "Make calls to Inference Providers" at https://huggingface.co/settings/tokens, then update this provider\'s API key.';
    }

    private function elapsed(int $startMs): int
    {
        return (int) (microtime(true) * 1000) - $startMs;
    }

    /**
     * @return list<string>
     */
    private function extractModelsFromJson(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        $list = $decoded['data'] ?? $decoded['models'] ?? null;
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $out[] = $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? $item['name'] ?? null;
            if (is_string($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function extractApiError(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        $candidate = $decoded['error']['message']
            ?? $decoded['error']
            ?? $decoded['message']
            ?? null;
        if (is_string($candidate) && $candidate !== '') {
            return substr($candidate, 0, 200);
        }

        return null;
    }

    /**
     * @throws AdapterRuntimeException When the Symfony AI runtime is not installed
     *                                 or its Factory cannot be resolved.
     */
    public function platform(Provider $provider): object
    {
        if (!$this->isRuntimeAvailable()) {
            throw new AdapterRuntimeException(sprintf(
                'Symfony AI Platform runtime is not installed. Add `composer require %s` to use adapter type "%s".',
                $this->descriptor->packageName,
                $this->descriptor->type,
            ));
        }

        $factoryClass = $this->resolveFactoryClass();
        if ($factoryClass === null || !class_exists($factoryClass)) {
            throw new AdapterRuntimeException(sprintf(
                'No platform factory found for package "%s". Tried classes: %s, %s, %s.',
                $this->descriptor->packageName,
                $this->expectedFactoryFqcn(),
                $this->expectedLegacyFactoryFqcn(),
                $this->expectedSealFactoryFqcn(),
            ));
        }

        if (!$this->supportsFactoryMethod($factoryClass)) {
            throw new AdapterRuntimeException(sprintf(
                'Factory %s has neither createPlatform() nor create() method.',
                $factoryClass,
            ));
        }

        $apiKey = $this->resolveApiKey($provider);
        /** @var object $platform */
        $platform = $this->createPlatformFromFactory($factoryClass, $provider, $apiKey);

        return $platform;
    }

    /**
     * @return list<string>
     */
    private function collectModelsFromCatalog(object $platform): array
    {
        if (!method_exists($platform, 'getModelCatalog')) {
            return [];
        }
        $catalog = $platform->getModelCatalog();
        if (!is_iterable($catalog)) {
            return [];
        }
        $models = [];
        foreach ($catalog as $model) {
            if (is_object($model) && method_exists($model, '__toString')) {
                $models[] = (string) $model;
                continue;
            }
            if (is_object($model)) {
                $models[] = (string) ($model->name ?? get_debug_type($model));
                continue;
            }
            $models[] = (string) $model;
        }

        return $models;
    }

    /**
     * Namespace prefix php-scoper applies to every class bundled in t3af.phar.
     * In classic mode the bridge factories live under this prefix; in Composer mode
     * they keep their original FQN. We probe both.
     */
    private const VENDOR_PREFIX = 'NITSAN\T3af\\Vendor\\';

    private function isRuntimeAvailable(): bool
    {
        foreach ($this->factoryCandidates() as $candidate) {
            if (class_exists($candidate)) {
                return true;
            }
        }

        return class_exists('Symfony\\AI\\Platform\\PlatformInterface')
            || class_exists(self::VENDOR_PREFIX . 'Symfony\\AI\\Platform\\PlatformInterface');
    }

    private function expectedFactoryFqcn(): string
    {
        return sprintf('Symfony\\AI\\Platform\\Bridge\\%s\\Factory', $this->pascalVendor());
    }

    private function expectedLegacyFactoryFqcn(): string
    {
        return sprintf('Symfony\\AI\\Platform\\Bridge\\%s\\PlatformFactory', $this->pascalVendor());
    }

    private function expectedSealFactoryFqcn(): string
    {
        return sprintf('Lochmueller\\SealAi\\Bridge\\%s\\PlatformFactory', $this->pascalVendor());
    }

    /**
     * Every factory FQN we accept, in priority order:
     *   1. The exact FQN supplied by PlatformRegistry (classic/phar mode) — authoritative,
     *      correctly cased (e.g. `…\Bridge\OpenAi\Factory`), so it survives the
     *      phar's case-sensitive classmap-authoritative autoloader.
     *   2. Derived un-scoped + vendor-prefixed guesses (Composer mode, or as a fallback).
     *
     * @return list<string>
     */
    private function factoryCandidates(): array
    {
        $out = [];
        if ($this->descriptor->factoryClass !== null) {
            $out[] = $this->descriptor->factoryClass;
        }

        $base = [
            $this->expectedFactoryFqcn(),
            $this->expectedLegacyFactoryFqcn(),
            $this->expectedSealFactoryFqcn(),
        ];
        foreach ($base as $fqcn) {
            $out[] = $fqcn;
            $out[] = self::VENDOR_PREFIX . $fqcn;
        }

        return $out;
    }

    private function resolveFactoryClass(): ?string
    {
        foreach ($this->factoryCandidates() as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function supportsFactoryMethod(string $factoryClass): bool
    {
        return method_exists($factoryClass, 'createPlatform') || method_exists($factoryClass, 'create');
    }

    private function createPlatformFromFactory(string $factoryClass, Provider $provider, string $apiKey): object
    {
        $method = method_exists($factoryClass, 'createPlatform') ? 'createPlatform' : 'create';

        if ($this->factoryExpectsEndpointFirst($factoryClass, $method)) {
            $endpoint = $this->resolveEndpoint($provider);
            if ($endpoint === null) {
                throw new AdapterRuntimeException(sprintf(
                    'Endpoint URL is required for adapter "%s". Example: http://host.docker.internal:11434 when TYPO3 runs in Docker.',
                    $this->descriptor->type,
                ));
            }

            $apiKeyArg = $apiKey !== '' ? $apiKey : null;

            /** @var object $platform */
            $platform = $factoryClass::{$method}($endpoint, $apiKeyArg);

            return $platform;
        }

        /** @var object $platform */
        $platform = $factoryClass::{$method}($apiKey);

        return $platform;
    }

    private function resolveEndpoint(Provider $provider): ?string
    {
        $endpoint = trim($provider->endpointUrl);
        if ($endpoint === '') {
            $endpoint = trim($this->descriptor->defaultEndpoint);
        }

        return $endpoint !== '' ? $endpoint : null;
    }

    private function factoryExpectsEndpointFirst(string $factoryClass, string $method): bool
    {
        if (!method_exists($factoryClass, $method)) {
            return false;
        }

        $parameters = (new \ReflectionMethod($factoryClass, $method))->getParameters();
        if ($parameters === []) {
            return false;
        }

        $first = $parameters[0]->getName();

        return $first === 'endpoint' || $first === 'baseUrl';
    }

    /**
     * `open-ai` → `OpenAi`, `bedrock` → `Bedrock`. Symfony AI namespaces the
     * bridge folders in PascalCase regardless of how the package name is
     * hyphenated.
     */
    private function pascalVendor(): string
    {
        $parts = explode('-', $this->descriptor->vendorKey);
        $parts = array_map(static fn(string $p): string => ucfirst($p), $parts);

        return implode('', $parts);
    }

    /**
     * @throws AdapterRuntimeException When the stored ciphertext cannot be decrypted.
     */
    private function resolveApiKey(Provider $provider): string
    {
        if ($provider->apiKeyCipher === '') {
            return '';
        }
        try {
            return $this->cipher->decrypt($provider->apiKeyCipher);
        } catch (CipherException $e) {
            throw new AdapterRuntimeException(
                sprintf('Cannot decrypt API key for provider "%s": %s', $provider->identifier, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function canonicalTypeKey(string $type): string
    {
        $prefix = 'symfony.';
        if (!str_starts_with($type, $prefix)) {
            return $type;
        }

        $vendor = substr($type, strlen($prefix));
        if ($vendor === false) {
            return $type;
        }

        return $prefix . str_replace('-', '', $vendor);
    }
}
