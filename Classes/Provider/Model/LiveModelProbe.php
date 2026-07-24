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

namespace NITSAN\NsT3AF\Provider\Model;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Service\CredentialCipher;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * HTTP probe that calls the vendor's `/models` (or equivalent) endpoint and
 * returns the advertised model IDs.
 *
 * Only IDs are returned — capability metadata is overlaid by
 * {@see SymfonyAiCatalogReader} and {@see CapabilityInferrer} in
 * {@see ModelDiscoveryService::discover()}.
 *
 * Probe map intentionally mirrors {@see \NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiBridgeAdapter::PROBE_CONFIG}
 * to keep the same auth semantics. Vendors without a usable list endpoint
 * (Azure deployments, DeepSeek, xAI) return [] so the catalog/inference path
 * remains authoritative.
 *
 * @internal
 */
class LiveModelProbe
{
    /**
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
        Provider::ADAPTER_OPENAI_COMPATIBLE => ['path' => '/models', 'auth' => 'bearer'],
    ];

    public function __construct(
        private readonly CredentialCipher $cipher,
        private readonly RequestFactory $requestFactory,
        private readonly AdapterRegistry $adapters,
    ) {}

    /**
     * @return list<string> Model IDs reported by the live endpoint; [] when no
     *                      probe is configured or the call failed.
     */
    public function probe(Provider $provider): array
    {
        $adapterType = Provider::normalizeAdapterType($provider->adapterType);
        $config = self::PROBE_CONFIG[$adapterType] ?? null;
        if ($config === null && str_starts_with($adapterType, 'symfony.')) {
            // OpenAI-compatible fallback for niche Symfony AI bridges (open-responses,
            // deepseek, xai, …). Most expose `/models` with a bearer token.
            $config = ['path' => '/models', 'auth' => 'bearer'];
        }
        if ($config === null) {
            return [];
        }
        $endpoint = $this->resolveEndpoint($adapterType, $provider->endpointUrl);
        if ($endpoint === '') {
            return [];
        }

        try {
            $apiKey = $this->resolveApiKey($provider);
        } catch (AdapterRuntimeException) {
            return [];
        }
        if ($config['auth'] !== 'none' && $apiKey === '') {
            return [];
        }

        $url = rtrim($endpoint, '/') . $config['path'];
        $headers = ['User-Agent' => 'ns_t3af/2.0', 'Accept' => 'application/json'];

        match ($config['auth']) {
            'bearer' => $headers['Authorization'] = 'Bearer ' . $apiKey,
            'x-api-key' => $headers['x-api-key'] = $apiKey,
            'query-key' => $url .= (str_contains($url, '?') ? '&' : '?') . 'key=' . urlencode($apiKey),
            default => null,
        };
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
        } catch (\Throwable) {
            return [];
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return [];
        }

        return $this->extractIds((string) $response->getBody());
    }

    /**
     * @return list<string>
     */
    private function extractIds(string $body): array
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
        $ids = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $ids[] = $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? $item['name'] ?? $item['model'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function resolveEndpoint(string $adapterType, string $configuredEndpoint): string
    {
        $endpoint = trim($configuredEndpoint);
        if ($endpoint !== '') {
            return $endpoint;
        }
        if (!$this->adapters->has($adapterType)) {
            return '';
        }

        return trim($this->adapters->get($adapterType)->getDefaultEndpoint());
    }

    /**
     * @throws AdapterRuntimeException When stored ciphertext cannot be decrypted.
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
}
