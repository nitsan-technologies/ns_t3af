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

namespace NITSAN\NsT3AF\Provider\OpenAiCompatible;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Service\CredentialCipher;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Built-in OpenAI-compatible HTTP adapter (Bearer + REST). Registers via DI like any
 * {@see AdapterInterface} — **no** {@code symfony/ai-openai-platform} requirement.
 *
 * Adapter id {@see Provider::ADAPTER_OPENAI_COMPATIBLE}. Legacy
 * {@code symfony.openai_compatible} values are normalized when hydrating providers or saving forms.
 *
 * @internal
 */
final class OpenAiCompatibleAdapter implements AdapterInterface
{
    public function __construct(
        private readonly CredentialCipher $cipher,
        private readonly RequestFactory $requestFactory,
    ) {}

    public function getType(): string
    {
        return Provider::ADAPTER_OPENAI_COMPATIBLE;
    }

    public function getDisplayName(): string
    {
        return 'Custom / Other';
    }

    public function getDefaultEndpoint(): string
    {
        return '';
    }

    /**
     * @return list<string>
     */
    public function getDefaultCapabilities(): array
    {
        return [
            Capability::CHAT,
            Capability::STREAMING,
            Capability::EMBEDDINGS,
            Capability::TTS,
            Capability::IMAGE_GENERATION,
        ];
    }

    public function testConnection(Provider $provider): VerifyResult
    {
        $start = (int) (microtime(true) * 1000);
        $endpoint = trim($provider->endpointUrl);
        if ($endpoint === '') {
            return VerifyResult::failure('Endpoint URL is required.', $this->elapsed($start));
        }

        try {
            $apiKey = $this->resolveApiKey($provider);
        } catch (AdapterRuntimeException $e) {
            return VerifyResult::failure($e->getMessage(), $this->elapsed($start));
        }

        if ($apiKey === '') {
            return VerifyResult::failure('API key is required.', $this->elapsed($start));
        }

        $url = rtrim($endpoint, '/') . '/models';
        $headers = [
            'User-Agent' => 'ns_t3af-openai-compatible/1.0',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];

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

    public function platform(Provider $provider): object
    {
        return new OpenAiCompatiblePlatform($provider, $this->cipher, $this->requestFactory);
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

        return is_string($candidate) && $candidate !== '' ? substr($candidate, 0, 200) : null;
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
}
