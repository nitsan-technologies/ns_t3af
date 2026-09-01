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

namespace NITSAN\NsT3AF\Provider\SymfonyAi;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;

/**
 * Wraps a Symfony AI Platform bridge for tool-calling chat completions.
 *
 * @internal
 */
final class SymfonyAiPlatform
{
    public function __construct(
        private readonly object $platform,
        private readonly Provider $provider,
        private readonly SymfonyAiMessageBagFactory $messageBagFactory,
    ) {}

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @return array{content: string, toolCalls: list<array{id: string, name: string, arguments: array<string, mixed>}>, usage?: array<string, mixed>, raw: array<string, mixed>}
     */
    public function invokeWithTools(string $modelId, array $messages, array $tools): array
    {
        if (!method_exists($this->platform, 'invoke')) {
            throw new AdapterRuntimeException('Symfony AI platform does not support invoke().');
        }

        $messageBag = $this->messageBagFactory->createFromChatMessages($messages);
        if ($messageBag === null) {
            throw new AdapterRuntimeException('No valid messages for Symfony AI tool calling.');
        }

        $options = ['tools' => $this->normalizeTools($tools)];
        if (!$this->isReasoningModel($modelId)) {
            $options['temperature'] = $this->provider->temperature;
        }

        $result = $this->platform->invoke($modelId, $messageBag, $options);
        $raw = $this->extractRawResponse($result);
        $content = $this->extractTextContent($result);
        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];

        return [
            'content' => $content,
            'toolCalls' => $this->extractToolCalls($raw),
            'usage' => $usage,
            'raw' => $raw,
        ];
    }

    /**
     * Plain completion entry point for {@see \NITSAN\NsT3AF\Service\AiService} duck typing.
     */
    public function invoke(string $modelId, mixed $payload): mixed
    {
        if (!method_exists($this->platform, 'invoke')) {
            throw new AdapterRuntimeException('Symfony AI platform does not support invoke().');
        }

        $options = [];
        if (!$this->isReasoningModel($modelId)) {
            $options['temperature'] = $this->provider->temperature;
        }

        if (is_string($payload)) {
            $messageBag = $this->messageBagFactory->createFromChatMessages([
                ['role' => 'user', 'content' => $payload],
            ]);
            if ($messageBag === null) {
                throw new AdapterRuntimeException('No valid messages for Symfony AI completion.');
            }

            return $this->platform->invoke($modelId, $messageBag, $options);
        }

        if (is_array($payload)) {
            if (isset($payload['messages']) && is_array($payload['messages'])) {
                $messages = $this->normalizeChatMessages($payload['messages']);
                if ($messages !== []) {
                    $messageBag = $this->messageBagFactory->createFromChatMessages($messages);
                    if ($messageBag !== null) {
                        return $this->platform->invoke($modelId, $messageBag, $options);
                    }
                }
            }

            return $this->platform->invoke($modelId, $payload, $options);
        }

        if (is_object($payload)) {
            return $this->platform->invoke($modelId, $payload, $options);
        }

        throw new AdapterRuntimeException('Unsupported Symfony AI invoke payload.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeChatMessages(mixed $messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $normalized = [];
        foreach ($messages as $message) {
            if (is_array($message)) {
                $normalized[] = $message;
            }
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private function normalizeTools(array $tools): array
    {
        $normalized = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            if (isset($tool['type']) && ($tool['type'] ?? '') === 'function' && is_array($tool['function'] ?? null)) {
                $normalized[] = $tool;
                continue;
            }
            $normalized[] = [
                'type' => 'function',
                'function' => $tool,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function extractToolCalls(array $raw): array
    {
        $calls = [];

        foreach ($raw['output'] ?? [] as $output) {
            if (!is_array($output) || ($output['type'] ?? '') !== 'function_call') {
                continue;
            }
            $parsed = $this->parseToolCallRow(
                (string) ($output['call_id'] ?? $output['id'] ?? ''),
                (string) ($output['name'] ?? ''),
                $output['arguments'] ?? '{}',
            );
            if ($parsed !== null) {
                $calls[] = $parsed;
            }
        }
        if ($calls !== []) {
            return $calls;
        }

        foreach ($raw['choices'] ?? [] as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
            foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $function = is_array($row['function'] ?? null) ? $row['function'] : [];
                $parsed = $this->parseToolCallRow(
                    (string) ($row['id'] ?? ''),
                    (string) ($function['name'] ?? ''),
                    $function['arguments'] ?? '{}',
                );
                if ($parsed !== null) {
                    $calls[] = $parsed;
                }
            }
        }
        if ($calls !== []) {
            return $calls;
        }

        foreach ($raw['content'] ?? [] as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $input = $block['input'] ?? [];
            $args = is_array($input) ? json_encode($input, JSON_THROW_ON_ERROR) : (string) $input;
            $parsed = $this->parseToolCallRow(
                (string) ($block['id'] ?? ''),
                (string) ($block['name'] ?? ''),
                $args,
            );
            if ($parsed !== null) {
                $calls[] = $parsed;
            }
        }

        return $calls;
    }

    /**
     * @return array{id: string, name: string, arguments: array<string, mixed>}|null
     */
    private function parseToolCallRow(string $id, string $name, mixed $arguments): ?array
    {
        if ($name === '') {
            return null;
        }

        if (is_array($arguments)) {
            $decoded = $arguments;
        } else {
            $argsJson = is_string($arguments) ? $arguments : '{}';
            $decoded = json_decode($argsJson, true);
            $decoded = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => $id !== '' ? $id : uniqid('call_', true),
            'name' => $name,
            'arguments' => $decoded,
        ];
    }

    private function extractTextContent(object $result): string
    {
        if (method_exists($result, 'asText')) {
            try {
                return trim((string) $result->asText(), "\"'");
            } catch (\Throwable) {
                // Fall through to raw response parsing.
            }
        }

        $raw = $this->extractRawResponse($result);
        foreach ($raw['choices'] ?? [] as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
            $content = $message['content'] ?? '';
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        foreach ($raw['content'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                return $block['text'];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawResponse(object $result): array
    {
        if (method_exists($result, 'getRawResult')) {
            try {
                $rawResult = $result->getRawResult();
                if (is_object($rawResult) && method_exists($rawResult, 'getData')) {
                    $data = $rawResult->getData();
                    if (is_array($data) && $data !== []) {
                        return $data;
                    }
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        if (method_exists($result, 'getResult')) {
            try {
                $inner = $result->getResult();
                if (is_object($inner) && method_exists($inner, 'getMetadata')) {
                    $metadata = $inner->getMetadata();
                    if (is_object($metadata) && method_exists($metadata, 'get')) {
                        $raw = $metadata->get('raw');
                        if (is_array($raw) && $raw !== []) {
                            return $raw;
                        }
                    }
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return [];
    }

    private function isReasoningModel(string $model): bool
    {
        foreach (['o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini'] as $prefix) {
            if ($model === $prefix || str_starts_with($model, $prefix . '-')) {
                return true;
            }
        }

        return false;
    }
}
