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

use Generator;
use NITSAN\NsT3AF\Api\ImageGenerationOptions;
use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Service\CredentialCipher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Minimal OpenAI-compatible HTTP client exposed as {@see invoke()}, {@see stream()},
 * {@see embed()} for {@see \NITSAN\NsT3AF\Service\AiService} duck typing.
 *
 * No Symfony AI bridge packages required — only TYPO3 {@see RequestFactory}.
 *
 * @internal
 */
final class OpenAiCompatiblePlatform
{
    public function __construct(
        private readonly Provider $provider,
        private readonly CredentialCipher $cipher,
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * Non-streaming chat completion (POST …/chat/completions).
     *
     * @param mixed $payload {@see AiService} passes string | array with messages/input shape.
     * @return array{content: string, usage?: array<string, mixed>, raw: array<string, mixed>}
     */
    public function invoke(string $modelId, mixed $payload): array
    {
        $messages = $this->normalizeMessages($payload);
        $body = [
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => $this->provider->temperature,
        ];

        $response = $this->postJson($this->chatCompletionsPath(), $body);
        $this->assertOk($response, 'chat/completions');

        $decoded = $this->decodeJsonBody($response);
        $content = $this->extractChatContent($decoded);
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        return [
            'content' => $content,
            'usage' => $usage,
            'raw' => $decoded,
        ];
    }

    /**
     * SSE streaming chat completion (POST …/chat/completions with stream: true).
     *
     * @param mixed $payload Same shapes as {@see invoke()}.
     * @return Generator<string>
     */
    public function stream(string $modelId, mixed $payload): Generator
    {
        $messages = $this->normalizeMessages($payload);
        $body = [
            'model' => $modelId,
            'messages' => $messages,
            'temperature' => $this->provider->temperature,
            'stream' => true,
        ];

        $response = $this->postJson($this->chatCompletionsPath(), $body, streamResponseBody: true);
        $this->assertOk($response, 'chat/completions (stream)');

        yield from $this->parseSseChatStream($response->getBody());
    }

    /**
     * Embeddings (POST …/embeddings).
     *
     * @param string|array<int|string, mixed> $text
     * @return array<string, mixed> Decoded API body (``data`` + ``usage``).
     */
    public function embed(string $modelId, string|array $text): array
    {
        $input = $text;
        $body = [
            'model' => $modelId,
            'input' => $input,
        ];

        $response = $this->postJson($this->embeddingsPath(), $body);
        $this->assertOk($response, 'embeddings');

        $decoded = $this->decodeJsonBody($response);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    /**
     * Text-to-speech synthesis (POST …/audio/speech).
     *
     * Returns raw binary audio data matching the requested `$options->format`.
     *
     * @throws AdapterRuntimeException On non-2xx response or decryption failure.
     */
    public function speech(string $modelId, string $text, TtsOptions $options): string
    {
        $body = [
            'model'           => $modelId,
            'input'           => $text,
            'voice'           => $options->voice,
            'response_format' => $options->format,
            'speed'           => $options->speed,
        ];

        $response = $this->postBinary('audio/speech', $body);
        $this->assertOk($response, 'audio/speech');

        return (string) $response->getBody();
    }

    /**
     * Image generation (POST …/images/generations).
     *
     * @return list<array{url?: string, b64_json?: string, revised_prompt?: string}>
     */
    public function generateImages(string $modelId, string $prompt, ImageGenerationOptions $options): array
    {
        $body = [
            'model' => $modelId,
            'prompt' => $prompt,
            'n' => max(1, min($options->count, 10)),
            'size' => $options->size,
        ];

        $response = $this->postJson($this->imagesGenerationsPath(), $body);
        $this->assertOk($response, 'images/generations');

        return $this->normalizeImageData($this->decodeJsonBody($response));
    }

    /**
     * Image variation (POST …/images/variations).
     *
     * @return list<array{url?: string, b64_json?: string, revised_prompt?: string}>
     */
    public function createImageVariation(string $modelId, string $imagePath, ImageGenerationOptions $options): array
    {
        if (!is_readable($imagePath)) {
            throw new AdapterRuntimeException(sprintf('Image variation source not readable: "%s"', $imagePath));
        }

        $multipart = [
            [
                'name' => 'image',
                'contents' => fopen($imagePath, 'r'),
            ],
            [
                'name' => 'n',
                'contents' => (string) max(1, min($options->count, 10)),
            ],
            [
                'name' => 'size',
                'contents' => $options->size,
            ],
        ];

        $response = $this->postMultipart($this->imagesVariationsPath(), $multipart);
        $this->assertOk($response, 'images/variations');

        return $this->normalizeImageData($this->decodeJsonBody($response));
    }

    private function postJson(string $path, array $body, bool $streamResponseBody = false): ResponseInterface
    {
        $url = $this->endpointUrl($path);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'ns_t3af-openai-compatible/1.0',
        ];
        $apiKey = $this->resolveApiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        $options = [
            'headers' => $headers,
            'json' => $body,
            'http_errors' => false,
            'timeout' => 120,
            'connect_timeout' => 10,
        ];
        if ($streamResponseBody) {
            $options['stream'] = true;
        }

        return $this->requestFactory->request($url, 'POST', $options);
    }

    private function postBinary(string $path, array $body): ResponseInterface
    {
        $url = $this->endpointUrl($path);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/octet-stream',
            'User-Agent'   => 'ns_t3af-openai-compatible/1.0',
        ];
        $apiKey = $this->resolveApiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $this->requestFactory->request($url, 'POST', [
            'headers'     => $headers,
            'json'        => $body,
            'http_errors' => false,
            'timeout'     => 120,
            'connect_timeout' => 10,
        ]);
    }

    private function endpointUrl(string $path): string
    {
        $base = $this->resolveBaseEndpoint();
        $suffix = ltrim($path, '/');

        if ($base === '') {
            throw new AdapterRuntimeException(sprintf(
                'Endpoint URL is required for provider "%s" (adapter "%s").',
                $this->provider->identifier,
                $this->provider->adapterType,
            ));
        }

        return $base . '/' . $suffix;
    }

    /**
     * Symfony AI chat bridges often leave {@see Provider::$endpointUrl} empty; image/TTS
     * routes still use this HTTP client and need the same vendor base URL.
     */
    private function resolveBaseEndpoint(): string
    {
        $endpoint = rtrim(trim($this->provider->endpointUrl), '/');
        if ($endpoint !== '') {
            return $endpoint;
        }

        return self::defaultEndpointForAdapter(
            Provider::normalizeAdapterType($this->provider->adapterType),
        );
    }

    private static function defaultEndpointForAdapter(string $adapterType): string
    {
        if (!str_starts_with($adapterType, 'symfony.')) {
            return '';
        }

        $vendor = substr($adapterType, strlen('symfony.'));

        return match ($vendor) {
            'openai' => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'ollama' => 'http://localhost:11434',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => '',
        };
    }

    /**
     * @throws AdapterRuntimeException When the stored ciphertext cannot be decrypted.
     */
    private function resolveApiKey(): string
    {
        if ($this->provider->apiKeyCipher === '') {
            return '';
        }
        try {
            return $this->cipher->decrypt($this->provider->apiKeyCipher);
        } catch (CipherException $e) {
            throw new AdapterRuntimeException(
                sprintf('Cannot decrypt API key for provider "%s": %s', $this->provider->identifier, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * @return list<array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    private function normalizeMessages(mixed $payload): array
    {
        $messages = [];
        $system = trim($this->provider->systemPrompt);
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        if (is_string($payload)) {
            $messages[] = ['role' => 'user', 'content' => $payload];

            return $messages;
        }

        if (!is_array($payload)) {
            return $messages;
        }

        if (isset($payload['messages']) && is_array($payload['messages'])) {
            foreach ($payload['messages'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $role = isset($row['role']) && is_string($row['role']) ? $row['role'] : 'user';
                $content = $row['content'] ?? null;
                $isEmpty = is_string($content)
                    ? ($content === '')
                    : (!is_array($content) || $content === []);
                if ($isEmpty) {
                    continue;
                }
                $messages[] = ['role' => $role, 'content' => $content];
            }

            return $messages;
        }

        if (isset($payload['input']) && is_string($payload['input']) && $payload['input'] !== '') {
            $messages[] = ['role' => 'user', 'content' => $payload['input']];
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractChatContent(array $decoded): string
    {
        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            return '';
        }
        $first = $choices[0];
        if (!is_array($first)) {
            return '';
        }
        $message = $first['message'] ?? null;
        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            return $message['content'];
        }
        $text = $first['text'] ?? null;

        return is_string($text) ? $text : '';
    }

    private function assertOk(ResponseInterface $response, string $context): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $response->getBody();
        $snippet = $this->extractApiError($body) ?? substr($body, 0, 400);

        throw new AdapterRuntimeException(
            sprintf('HTTP %d (%s): %s', $status, $context, $snippet),
            $status,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
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

        return is_string($candidate) && $candidate !== '' ? substr($candidate, 0, 400) : null;
    }

    /**
     * @return Generator<string>
     */
    private function parseSseChatStream(StreamInterface $stream): Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') {
                    continue;
                }
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $json = trim(substr($line, strlen('data:')));
                $data = json_decode($json, true);
                if (!is_array($data)) {
                    continue;
                }
                $choices = $data['choices'] ?? null;
                if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
                    continue;
                }
                $delta = $choices[0]['delta'] ?? null;
                if (is_array($delta) && isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                    yield $delta['content'];
                }
            }
        }
    }

    /**
     * Ollama exposes OpenAI-compatible routes under /v1 while the stored base URL is
     * usually host:11434 only. Custom gateways typically already include /v1 in the base.
     */
    private function chatCompletionsPath(): string
    {
        return $this->usesOllamaV1RoutePrefix() ? '/v1/chat/completions' : '/chat/completions';
    }

    private function embeddingsPath(): string
    {
        return $this->usesOllamaV1RoutePrefix() ? '/v1/embeddings' : '/embeddings';
    }

    private function imagesGenerationsPath(): string
    {
        return $this->usesOllamaV1RoutePrefix() ? '/v1/images/generations' : '/images/generations';
    }

    private function imagesVariationsPath(): string
    {
        return $this->usesOllamaV1RoutePrefix() ? '/v1/images/variations' : '/images/variations';
    }

    /**
     * @param list<array{name: string, contents: mixed}> $multipart
     */
    private function postMultipart(string $path, array $multipart): ResponseInterface
    {
        $url = $this->endpointUrl($path);
        $headers = [
            'User-Agent' => 'ns_t3af-openai-compatible/1.0',
            'Accept' => 'application/json',
        ];
        $apiKey = $this->resolveApiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $this->requestFactory->request($url, 'POST', [
            'headers' => $headers,
            'multipart' => $multipart,
            'http_errors' => false,
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array{url?: string, b64_json?: string, revised_prompt?: string}>
     */
    private function normalizeImageData(array $decoded): array
    {
        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $images = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entry = [];
            if (isset($item['url']) && is_string($item['url'])) {
                $entry['url'] = $item['url'];
            }
            if (isset($item['b64_json']) && is_string($item['b64_json'])) {
                $entry['b64_json'] = $item['b64_json'];
            }
            if (isset($item['revised_prompt']) && is_string($item['revised_prompt'])) {
                $entry['revised_prompt'] = $item['revised_prompt'];
            }
            if ($entry !== []) {
                $images[] = $entry;
            }
        }

        return $images;
    }

    private function usesOllamaV1RoutePrefix(): bool
    {
        $base = $this->resolveBaseEndpoint();
        if ($base === '' || str_ends_with($base, '/v1')) {
            return false;
        }

        if (Provider::normalizeAdapterType($this->provider->adapterType) === Provider::ADAPTER_SYMFONY_OLLAMA) {
            return true;
        }

        return preg_match('#:11434$#', $base) === 1;
    }
}
