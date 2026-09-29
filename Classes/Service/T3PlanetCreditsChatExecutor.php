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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCall;
use NITSAN\NsT3AF\Api\AiToolCallingResponse;
use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
use NITSAN\NsT3AF\Credits\Platform\T3PlanetCreditsPlatformFactory;
use NITSAN\NsT3AF\Credits\Service\CreditsChargeRecorder;
use NITSAN\NsT3AF\Credits\Service\CreditsMetaJsonBuilder;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditsModelsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiMessageBagFactory;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiResultReader;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * One agent model round through Credits {@code /v1/chat/completions} (OpenAI wire + tools).
 *
 * @phpstan-import-type JsonSchema from \Symfony\AI\Platform\Contract\JsonSchema\Factory
 *
 * @internal
 */
class T3PlanetCreditsChatExecutor
{
    private const FEATURE_KEY = 'agent_chat';

    public function __construct(
        private readonly T3PlanetCreditsPlatformFactory $platformFactory,
        private readonly T3PlanetCreditsModelsService $modelsService,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsChargeRecorder $chargeRecorder,
        private readonly SymfonyAiMessageBagFactory $messageBagFactory,
    ) {}

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<AiToolDefinition> $tools
     */
    public function completeWithTools(array $messages, array $tools, AiOptions $options): AiToolCallingResponse
    {
        $modelAlias = $this->resolveModelAlias($options);
        $requestUuid = $this->freshRequestUuid($options);
        $turnId = $this->turnId($options);
        $toolPayload = array_map(
            static fn(AiToolDefinition $definition): array => $definition->toProviderShape(),
            $tools,
        );

        $start = (int) (microtime(true) * 1000);
        try {
            $raw = $this->invokeWithRetries($messages, $toolPayload, $options, $modelAlias, $requestUuid, $turnId);
        } catch (InsufficientCreditsException|CreditsApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw $this->mapThrowable($exception);
        }

        $latencyMs = (int) (microtime(true) * 1000) - $start;
        $this->recordCharge($requestUuid, $raw, $options, $latencyMs, $modelAlias);

        $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
        $content = $this->extractContent($raw);
        $toolCalls = $this->extractToolCalls($raw);

        return new AiToolCallingResponse(
            content: $content,
            modelId: $modelAlias,
            providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
            toolCalls: $toolCalls,
            tokensInput: (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            tokensOutput: (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
            raw: $raw,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array{name: string, description: string, parameters: array<string, mixed>|\stdClass}> $toolPayload
     * @return array<string, mixed>
     */
    private function invokeWithRetries(
        array $messages,
        array $toolPayload,
        AiOptions $options,
        string $modelAlias,
        string &$requestUuid,
        string $turnId,
    ): array {
        try {
            return $this->invokeOnce($messages, $toolPayload, $options, $modelAlias, $requestUuid, $turnId);
        } catch (CreditsApiException $exception) {
            if ($exception->errorCode === CreditsApiErrorCodes::IDEMPOTENCY_CONFLICT) {
                $requestUuid = Uuid::v4()->toRfc4122();

                return $this->invokeOnce($messages, $toolPayload, $options, $modelAlias, $requestUuid, $turnId);
            }
            if ($this->tokenResolver->invalidateOnUnauthorized($exception)) {
                return $this->invokeOnce(
                    $messages,
                    $toolPayload,
                    $options,
                    $modelAlias,
                    $requestUuid,
                    $turnId,
                    $this->tokenResolver->issueFreshToken(),
                );
            }
            throw $exception;
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array{name: string, description: string, parameters: array<string, mixed>|\stdClass}> $toolPayload
     * @return array<string, mixed>
     */
    private function invokeOnce(
        array $messages,
        array $toolPayload,
        AiOptions $options,
        string $modelAlias,
        string $requestUuid,
        string $turnId,
        #[\SensitiveParameter]
        ?string $bearerToken = null,
    ): array {
        $messageBag = $this->messageBagFactory->createFromChatMessages($messages);
        if ($messageBag === null) {
            throw new AdapterRuntimeException('No valid messages for T3Planet Credits chat.');
        }

        $meta = $this->buildMetadata($options, $requestUuid, $turnId);
        $invokeOptions = [
            'tools' => $this->normalizeTools($toolPayload),
            'tool_choice' => 'auto',
            'stream' => false,
            'metadata' => $meta,
        ];
        if ($options->temperature !== null) {
            $invokeOptions['temperature'] = $options->temperature;
        }
        if ($options->maxTokens !== null && $options->maxTokens > 0) {
            $invokeOptions['max_tokens'] = $options->maxTokens;
        }

        if ($modelAlias === '') {
            throw new AdapterRuntimeException('T3Planet Credits model alias is empty.');
        }

        try {
            $platform = $this->platformFactory->create(
                $bearerToken !== null && $bearerToken !== '' ? $bearerToken : null,
            );
            $result = $platform->invoke($modelAlias, $messageBag, $invokeOptions);
        } catch (\Throwable $exception) {
            throw $this->mapThrowable($exception);
        }

        $raw = $this->extractRawResponse($result);
        if ($raw === []) {
            // Still a usable Symfony result (text / tool calls) without raw JSON.
            $content = SymfonyAiResultReader::text($result);
            $toolCalls = SymfonyAiResultReader::toolCalls($result);
            $raw = [
                'choices' => [[
                    'message' => [
                        'content' => $content,
                        'tool_calls' => array_map(
                            static fn(array $call): array => [
                                'id' => $call['id'],
                                'type' => 'function',
                                'function' => [
                                    'name' => $call['name'],
                                    'arguments' => json_encode($call['arguments'], JSON_THROW_ON_ERROR),
                                ],
                            ],
                            $toolCalls,
                        ),
                    ],
                    'finish_reason' => $toolCalls !== [] ? 'tool_calls' : 'stop',
                ]],
                'model' => $modelAlias,
            ];
        }

        if (array_key_exists('error', $raw)) {
            throw $this->exceptionFromOpenAiErrorBody($raw, new AdapterRuntimeException(
                $this->errorPreview($raw) !== '' ? $this->errorPreview($raw) : 'Credits chat returned an error payload.',
            ));
        }

        return $raw;
    }

    /**
     * Build {@see Tool} instances so Symfony Serializer never sees {@see \stdClass}
     * (empty JSON Schema `{}` from {@see AiToolDefinition::toProviderShape()}).
     *
     * @param list<array{name: string, description: string, parameters: array<string, mixed>|\stdClass}> $tools
     * @return list<Tool>
     */
    private function normalizeTools(array $tools): array
    {
        $normalized = [];
        foreach ($tools as $tool) {
            $name = trim((string) ($tool['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = (string) ($tool['description'] ?? '');
            $parameters = isset($tool['parameters']) && is_array($tool['parameters'])
                ? $this->normalizeParametersForTool($tool['parameters'])
                : null;
            $normalized[] = new Tool(
                new ExecutionReference(self::class, '__invoke'),
                $name,
                $description,
                $parameters,
            );
        }

        return $normalized;
    }

    /**
     * OpenAI rejects JSON Schema {@code properties: []}. No-arg tools omit parameters.
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
            $type = $definition['type'] ?? 'string';
            $properties[$key] = [
                'type' => is_string($type) && $type !== '' ? $type : 'string',
                'description' => is_string($definition['description'] ?? null)
                    ? (string) $definition['description']
                    : '',
            ];
            if (isset($definition['enum']) && is_array($definition['enum'])) {
                $enum = array_values(array_filter(
                    $definition['enum'],
                    static fn(mixed $v): bool => is_string($v),
                ));
                if ($enum !== []) {
                    $properties[$key]['enum'] = $enum;
                }
            }
        }

        $required = [];
        foreach ($rawRequired as $name) {
            if (is_string($name) && $name !== '' && isset($properties[$name])) {
                $required[] = $name;
            }
        }

        if ($properties === []) {
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
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function parametersAsArray(array $parameters): array
    {
        $encoded = json_encode($parameters, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $parameters;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function errorPreview(array $raw): string
    {
        $error = $raw['error'] ?? null;
        if (is_string($error)) {
            return substr(trim($error), 0, 200);
        }
        if (is_array($error)) {
            $message = $error['message'] ?? $error['code'] ?? null;
            if (is_string($message) && $message !== '') {
                return substr($message, 0, 200);
            }

            return substr(json_encode($error, JSON_UNESCAPED_UNICODE) ?: '', 0, 200);
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(AiOptions $options, string $requestUuid, string $turnId): array
    {
        $meta = [
            'request_uuid' => $requestUuid,
            'feature_key' => self::FEATURE_KEY,
            'client_feature_key' => trim((string) ($options->featureKey ?? '')) !== ''
                ? (string) $options->featureKey
                : 'agent.nl_turn',
            // If present, server requires UUID v4 (AiKeyGenerator::isUuidV4).
            'turn_id' => $this->normalizeTurnId($turnId),
        ];
        $domain = trim($this->platformFactory->domain());
        if ($domain !== '') {
            $meta['domain'] = $domain;
        }

        $attributed = CreditsMetaJsonBuilder::withAttribution($meta, $options);
        unset($attributed['messages'], $attributed['brandContextScope']);

        // OpenAI-compatible metadata values must be strings; ints (page_id) get rejected.
        foreach ($attributed as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $attributed[$key] = (string) $value;
            }
        }

        return $attributed;
    }

    private function turnId(AiOptions $options): string
    {
        $turnId = $options->extra['turn_id'] ?? null;

        return is_string($turnId) ? trim($turnId) : '';
    }

    /**
     * Credits {@see ChatRequestValidator} requires metadata.turn_id to be UUID v4
     * ({@code isUuidV4}). UUID v5 / bare hex correlation ids are rejected as required_field_missing.
     */
    private function normalizeTurnId(string $turnId): string
    {
        $turnId = trim($turnId);
        if ($turnId !== '' && preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $turnId,
        ) === 1) {
            return strtolower($turnId);
        }

        return Uuid::v4()->toRfc4122();
    }

    private function resolveModelAlias(AiOptions $options): string
    {
        $fromModel = trim((string) ($options->modelId ?? ''));
        if ($fromModel !== '' && $fromModel !== 'default') {
            return $fromModel;
        }
        $fromProvider = trim((string) ($options->providerIdentifier ?? ''));
        if (
            $fromProvider !== ''
            && $fromProvider !== 'default'
            && $fromProvider !== CreditsProviderIdentifier::IDENTIFIER
        ) {
            return $fromProvider;
        }

        return $this->modelsService->resolveDefaultAlias();
    }

    private function freshRequestUuid(AiOptions $options): string
    {
        $given = trim($options->requestUuid);

        return $given !== '' ? $given : Uuid::v4()->toRfc4122();
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function recordCharge(
        string $requestUuid,
        array $raw,
        AiOptions $options,
        int $latencyMs,
        string $modelAlias,
    ): void {
        $t3planet = is_array($raw['t3planet'] ?? null) ? $raw['t3planet'] : [];
        $chargedFlag = (bool) ($t3planet['charged'] ?? false);
        if (!$chargedFlag && !isset($raw['usage'])) {
            return;
        }

        $creditsAmount = $t3planet['credits'] ?? $t3planet['cost'] ?? $t3planet['cost_units'] ?? 0;
        $payload = [
            'status' => true,
            'model' => (string) ($raw['model'] ?? $modelAlias),
            'charged' => [
                'feature_key' => self::FEATURE_KEY,
                'model' => (string) ($raw['model'] ?? $modelAlias),
                'bucket' => (string) ($t3planet['bucket'] ?? ''),
                'cost_units' => is_numeric($creditsAmount) ? (int) $creditsAmount : 0,
                'credits' => is_numeric($creditsAmount) ? (float) $creditsAmount : 0.0,
            ],
            'credits' => is_array($t3planet['balance'] ?? null)
                ? $t3planet['balance']
                : (is_array($t3planet['credits'] ?? null) ? $t3planet['credits'] : []),
            'usage' => is_array($raw['usage'] ?? null) ? $raw['usage'] : [],
            't3planet' => $t3planet,
        ];

        $this->chargeRecorder->record(
            $requestUuid,
            self::FEATURE_KEY,
            $payload,
            CreditsChargeRecorder::contextFromAiOptions($options, $latencyMs),
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function extractContent(array $raw): string
    {
        $choice = is_array($raw['choices'][0] ?? null) ? $raw['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = $message['content'] ?? $raw['content'] ?? '';

        return is_string($content) ? $content : '';
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<AiToolCall>
     */
    private function extractToolCalls(array $raw): array
    {
        $choice = is_array($raw['choices'][0] ?? null) ? $raw['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $calls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];
        if ($calls === [] && is_array($raw['toolCalls'] ?? null)) {
            $calls = $raw['toolCalls'];
        }

        $result = [];
        foreach ($calls as $row) {
            if (!is_array($row)) {
                continue;
            }
            $function = is_array($row['function'] ?? null) ? $row['function'] : $row;
            $name = isset($function['name']) && is_string($function['name']) ? $function['name'] : '';
            if ($name === '') {
                continue;
            }
            $arguments = $function['arguments'] ?? $row['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($arguments)) {
                $arguments = [];
            }
            $result[] = new AiToolCall(
                id: isset($row['id']) && is_string($row['id']) ? $row['id'] : uniqid('call_', true),
                name: $name,
                arguments: $arguments,
            );
        }

        return $result;
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

        return [];
    }

    private function mapThrowable(\Throwable $exception): \Throwable
    {
        if ($exception instanceof InsufficientCreditsException || $exception instanceof CreditsApiException) {
            return $exception;
        }

        $body = $this->extractHttpErrorBody($exception);
        if ($body !== null) {
            return $this->exceptionFromOpenAiErrorBody($body, $exception);
        }

        return new CreditsApiException(
            CreditsApiErrorCodes::UPSTREAM_AI_ERROR,
            502,
            $exception->getMessage() !== '' ? $exception->getMessage() : CreditsApiErrorCodes::UPSTREAM_AI_ERROR,
            [],
            $exception,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractHttpErrorBody(\Throwable $exception): ?array
    {
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof HttpExceptionInterface) {
                try {
                    $decoded = json_decode($current->getResponse()->getContent(false), true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                } catch (\Throwable) {
                    // Continue walking.
                }
            }
            $current = $current->getPrevious();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function exceptionFromOpenAiErrorBody(array $body, \Throwable $previous): CreditsApiException
    {
        $nested = is_array($body['error'] ?? null) ? $body['error'] : null;
        $code = (string) ($nested['code'] ?? $body['error_code'] ?? $body['code'] ?? CreditsApiErrorCodes::API_ERROR);
        $message = (string) ($nested['message'] ?? $body['message'] ?? $code);
        $topup = (string) ($nested['topup_url'] ?? $body['topup_url'] ?? '');
        $status = CreditsApiErrorCodes::httpStatus($code);

        if ($code === CreditsApiErrorCodes::INSUFFICIENT_CREDITS || $status === 402) {
            return new InsufficientCreditsException(
                $message !== $code ? $message : 'Insufficient credits',
                $topup,
                is_array($nested) ? $nested : $body,
                $previous,
            );
        }

        return new CreditsApiException($code, $status, $message, is_array($nested) ? $nested : [], $previous);
    }
}
