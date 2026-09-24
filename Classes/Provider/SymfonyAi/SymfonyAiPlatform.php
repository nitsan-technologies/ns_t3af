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

use const JSON_THROW_ON_ERROR;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;

/**
 * Wraps a Symfony AI Platform bridge for tool-calling chat completions.
 *
 * @phpstan-import-type JsonSchema from \Symfony\AI\Platform\Contract\JsonSchema\Factory
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

        $options = [
            'tools' => $this->normalizeTools($tools),
            'tool_choice' => 'auto',
        ];
        if (!$this->isReasoningModel($modelId)) {
            $options['temperature'] = $this->provider->temperature;
        }

        $result = $this->platform->invoke($modelId, $messageBag, $options);
        $raw = $this->extractRawResponse($result);
        // Result objects first: reasoning models answer with several parts (thinking + text / tool calls).
        $content = SymfonyAiResultReader::text($result);
        if (trim($content) === '') {
            $content = $this->extractTextContent($result);
        }
        $toolCalls = SymfonyAiResultReader::toolCalls($result);
        if ($toolCalls === []) {
            $toolCalls = $this->extractToolCalls($raw);
        }
        $thinking = SymfonyAiResultReader::thinking($result);
        if ($thinking !== '' && !isset($raw['thinking'])) {
            $raw['thinking'] = $thinking;
        }
        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];

        // Provider errors must not become an empty "I could not produce a reply".
        if (trim($content) === '' && $toolCalls === []) {
            $apiErrorMessage = $this->extractApiErrorMessage($raw);
            if ($apiErrorMessage !== '') {
                throw new AdapterRuntimeException($apiErrorMessage);
            }
            if (is_object($result) && method_exists($result, 'getResult')) {
                try {
                    $result->getResult();
                } catch (\Throwable $exception) {
                    $message = trim($exception->getMessage());
                    if ($message !== '') {
                        throw new AdapterRuntimeException($message, 0, $exception);
                    }
                }
            }
        }

        return [
            'content' => $content,
            'toolCalls' => $toolCalls,
            'usage' => $usage,
            'raw' => $raw,
        ];
    }

    /**
     * Embeddings entry point for {@see \NITSAN\NsT3AF\Service\AiService} duck typing.
     *
     * Must pass the raw string / list of strings through to the bridge. Chat-oriented
     * {@see invoke()} wraps strings as MessageBag, which Symfony normalizes into a
     * messages structure; OpenAI Embeddings then rejects that as:
     * Invalid 'input': expected a string or token array.
     *
     * @param string|list<string> $text
     */
    public function embed(string $modelId, string|array $text): mixed
    {
        if (!method_exists($this->platform, 'invoke')) {
            throw new AdapterRuntimeException('Symfony AI platform does not support invoke().');
        }

        // No chat temperature / tools options — embeddings only accept model + input.
        return $this->platform->invoke($modelId, $text, []);
    }

    /**
     * Plain completion entry point for {@see \NITSAN\NsT3AF\Service\AiService} duck typing.
     *
     * @param array<string, mixed> $options
     */
    public function invoke(string $modelId, mixed $payload, array $options = []): mixed
    {
        if (!method_exists($this->platform, 'invoke')) {
            throw new AdapterRuntimeException('Symfony AI platform does not support invoke().');
        }

        if (!$this->isReasoningModel($modelId) && !array_key_exists('temperature', $options)) {
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
     * Build Symfony {@see \Symfony\AI\Platform\Tool\Tool} instances so the bridge
     * contract can normalize them per model (OpenAI Responses wants tools[n].name;
     * chat-completions shape tools[n].function.name is rejected by Responses).
     *
     * @param list<array<string, mixed>|object> $tools
     * @return list<object|array<string, mixed>>
     */
    private function normalizeTools(array $tools): array
    {
        $toolClass = 'Symfony\\AI\\Platform\\Tool\\Tool';
        $referenceClass = 'Symfony\\AI\\Platform\\Tool\\ExecutionReference';
        $canBuildTool = class_exists($toolClass) && class_exists($referenceClass);

        $normalized = [];
        foreach ($tools as $tool) {
            if ($canBuildTool && is_object($tool) && is_a($tool, $toolClass, false)) {
                $normalized[] = $tool;
                continue;
            }
            if (!is_array($tool)) {
                continue;
            }
            $function = $tool;
            if (($tool['type'] ?? '') === 'function' && is_array($tool['function'] ?? null)) {
                $function = $tool['function'];
            }
            $name = trim((string) ($function['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = (string) ($function['description'] ?? '');
            $parameters = isset($function['parameters']) && is_array($function['parameters'])
                ? $this->normalizeParametersForTool($function['parameters'])
                : null;

            if ($canBuildTool) {
                $normalized[] = new $toolClass(
                    new $referenceClass(self::class, '__invoke'),
                    $name,
                    $description,
                    $parameters,
                );
                continue;
            }

            // Fallback when Symfony Tool classes are unavailable: Responses-style flat shape.
            $payload = [
                'type' => 'function',
                'name' => $name,
                'description' => $description,
            ];
            if ($parameters !== null) {
                $payload['parameters'] = $parameters;
            }
            $normalized[] = $this->serializerSafeToolPayload($payload);
        }

        return $normalized;
    }

    /**
     * OpenAI Responses rejects JSON Schema `properties: []` (must be object `{}`).
     * No-arg tools omit parameters entirely (same as Symfony OpenResponses ToolNormalizer with null).
     *
     * @param array<string, mixed> $parameters
     * @return JsonSchema|null
     */
    private function normalizeParametersForTool(array $parameters): ?array
    {
        $parameters = $this->parametersAsArray($parameters);
        $rawProperties = $parameters['properties'] ?? [];
        if (!is_array($rawProperties)) {
            $rawProperties = [];
        }
        $rawRequired = $parameters['required'] ?? [];
        if (!is_array($rawRequired)) {
            $rawRequired = [];
        }

        $properties = [];
        foreach ($rawProperties as $key => $definition) {
            if (!is_string($key) || $key === '' || !is_array($definition)) {
                continue;
            }
            $properties[$key] = $this->sanitizeSchema($definition);
        }

        $required = [];
        foreach ($rawRequired as $name) {
            if (is_string($name) && $name !== '' && isset($properties[$name])) {
                $required[] = $name;
            }
        }

        if ($properties === [] && $required === []) {
            return null;
        }

        /** @var JsonSchema $schema */
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];

        return $schema;
    }

    /**
     * Cleans one (nested) JSON Schema node without changing its meaning:
     * - keeps union types (`["string","null"]`) and `anyOf`/`oneOf`/`allOf` as they are;
     * - defaults `type` to string only when the node declares no type at all;
     * - drops empty or non-string descriptions instead of sending `""`;
     * - removes empty `properties` (a PHP `[]` would be sent as a JSON list, which
     *   OpenAI rejects) and `required` entries that name no property, at every level.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function sanitizeSchema(array $schema): array
    {
        $composite = ['anyOf', 'oneOf', 'allOf'];
        $declaresType = isset($schema['type']) || isset($schema['enum']) || isset($schema['const']) || isset($schema['$ref']);
        foreach ($composite as $keyword) {
            $declaresType = $declaresType || isset($schema[$keyword]);
        }
        if (!$declaresType) {
            $schema['type'] = 'string';
        } elseif (isset($schema['type']) && !is_string($schema['type']) && !is_array($schema['type'])) {
            unset($schema['type']);
            if (!isset($schema['anyOf']) && !isset($schema['oneOf']) && !isset($schema['allOf'])) {
                $schema['type'] = 'string';
            }
        }

        if (array_key_exists('description', $schema) && (!is_string($schema['description']) || trim($schema['description']) === '')) {
            unset($schema['description']);
        }

        if (array_key_exists('properties', $schema)) {
            $properties = [];
            foreach (is_array($schema['properties']) ? $schema['properties'] : [] as $key => $definition) {
                if (is_string($key) && $key !== '' && is_array($definition)) {
                    $properties[$key] = $this->sanitizeSchema($definition);
                }
            }
            if ($properties === []) {
                unset($schema['properties']);
            } else {
                $schema['properties'] = $properties;
            }
        }

        if (array_key_exists('required', $schema)) {
            $known = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = array_values(array_filter(
                is_array($schema['required']) ? $schema['required'] : [],
                static fn(mixed $name): bool => is_string($name) && isset($known[$name]),
            ));
            if ($required === []) {
                unset($schema['required']);
            } else {
                $schema['required'] = $required;
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = array_is_list($schema['items']) && $schema['items'] !== []
                ? array_map(fn(mixed $item): mixed => is_array($item) ? $this->sanitizeSchema($item) : $item, $schema['items'])
                : $this->sanitizeSchema($schema['items']);
        }
        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            $schema['additionalProperties'] = $this->sanitizeSchema($schema['additionalProperties']);
        }
        foreach ($composite as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $schema[$keyword] = array_values(array_map(
                    fn(mixed $branch): mixed => is_array($branch) ? $this->sanitizeSchema($branch) : $branch,
                    $schema[$keyword],
                ));
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function parametersAsArray(array $parameters): array
    {
        // Drop stdClass (empty JSON Schema `{}`) so Tool + serializer stay happy.
        $encoded = json_encode($parameters, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $parameters;
    }

    /**
     * Symfony Serializer cannot normalize {@see \stdClass} (used for empty JSON Schema `{}`).
     *
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function serializerSafeToolPayload(array $tool): array
    {
        $encoded = json_encode($tool, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)) {
            return $tool;
        }

        return $decoded;
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

        // OpenAI Responses API: output[type=message].content[type=output_text].text
        $text = '';
        foreach (is_array($raw['output'] ?? null) ? $raw['output'] : [] as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }
        if ($text !== '') {
            return $text;
        }

        // Gemini: candidates[0].content.parts[].text (thought parts excluded)
        $candidate = is_array($raw['candidates'][0] ?? null) ? $raw['candidates'][0] : [];
        foreach (is_array($candidate['content']['parts'] ?? null) ? $candidate['content']['parts'] : [] as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && ($part['thought'] ?? false) !== true) {
                $text .= $part['text'];
            }
        }

        return $text;
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

    /**
     * @param array<string, mixed> $raw
     */
    private function extractApiErrorMessage(array $raw): string
    {
        $error = $raw['error'] ?? null;
        if (is_string($error) && trim($error) !== '') {
            return trim($error);
        }
        if (is_array($error)) {
            foreach (['message', 'error', 0] as $key) {
                $candidate = $error[$key] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }
        if (is_object($error) && method_exists($error, 'getMessage')) {
            $message = trim((string) $error->getMessage());
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }

    private function isReasoningModel(string $model): bool
    {
        $normalized = strtolower(trim($model));
        foreach (['o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini', 'gpt-5'] as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix . '-') || str_starts_with($normalized, $prefix . '.')) {
                return true;
            }
        }

        return str_contains($normalized, 'gpt-5');
    }
}
