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

namespace NITSAN\NsT3AF\Agent\Runtime;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Api\AiToolDefinition;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Symfony AI platform that sends every model round through {@see AiToolCallingServiceInterface}.
 *
 * So the Symfony Agent loop keeps everything T3AF adds around a provider call: provider and
 * model resolution, AI Access (BeforeProviderRequestEvent), T3Planet Credits, telemetry and logs.
 * The model name passed by the agent is ignored; the provider decides.
 *
 * Tools are re-read from the toolbox on every round, so tools loaded during a turn
 * (for example by a tool search) are offered on the next round.
 *
 * @internal
 */
final class GovernedPlatform implements PlatformInterface
{
    /**
     * @param \Closure(list<array<string, mixed>>): AiOptions $optionsFactory
     */
    public function __construct(
        private readonly AiToolCallingServiceInterface $toolCallingService,
        private readonly AgentTurnState $state,
        private readonly \Closure $optionsFactory,
        private readonly ?ToolboxInterface $toolbox = null,
        private readonly bool $showThinking = false,
    ) {}

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        if (!$input instanceof MessageBag) {
            throw new InvalidArgumentException('The governed platform expects a MessageBag.');
        }

        $messages = $this->toChatMessages($input);
        $tools = $this->toToolDefinitions(array_values($this->toolbox?->getTools() ?? $this->toolsFromOptions($options)));

        $this->state->emit('progress', ['status' => 'llm', 'iteration' => $this->state->modelRequests]);
        $this->state->emit('model_request', ['round' => $this->state->modelRequests + 1]);
        ++$this->state->modelRequests;

        $response = $this->toolCallingService->completeWithTools($messages, $tools, ($this->optionsFactory)($messages));

        if (array_key_exists('cancelled', $response->raw)) {
            $this->state->cancel(trim((string) $response->raw['cancelled']));

            return $this->deferred(new TextResult(''), $response->raw, $options);
        }

        $this->state->modelId = $response->modelId;
        $this->state->providerIdentifier = $response->providerIdentifier;
        $this->recordThinking($response->raw);

        if ($response->toolCalls !== []) {
            $calls = [];
            foreach ($response->toolCalls as $call) {
                $calls[] = new ToolCall($call->id !== '' ? $call->id : uniqid('call_', true), $call->name, $call->arguments);
            }

            $result = new ToolCallResult($calls);
            if (trim($response->content) !== '') {
                $result->getMetadata()->add('text', trim($response->content));
            }

            return $this->deferred($result, $response->raw, $options);
        }

        return $this->deferred(new TextResult($response->content), $response->raw, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return new FallbackModelCatalog();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toChatMessages(MessageBag $bag): array
    {
        $messages = [];
        foreach ($bag->getMessages() as $message) {
            if ($message instanceof SystemMessage) {
                $content = $message->getContent();
                $messages[] = ['role' => 'system', 'content' => is_string($content) ? $content : (string) $content];
            } elseif ($message instanceof UserMessage) {
                $messages[] = ['role' => 'user', 'content' => (string) $message->asText()];
            } elseif ($message instanceof AssistantMessage) {
                $row = ['role' => 'assistant', 'content' => $message->asText()];
                if ($message->hasToolCalls()) {
                    $row['tool_calls'] = array_map(
                        static fn(ToolCall $call): array => [
                            'id' => $call->getId(),
                            'name' => $call->getName(),
                            'arguments' => $call->getArguments(),
                        ],
                        array_values($message->getToolCalls()),
                    );
                }
                $messages[] = $row;
            } elseif ($message instanceof ToolCallMessage) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message->getToolCall()->getId(),
                    'name' => $message->getToolCall()->getName(),
                    'content' => (string) $message->asText(),
                ];
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<Tool>
     */
    private function toolsFromOptions(array $options): array
    {
        $tools = is_array($options['tools'] ?? null) ? $options['tools'] : [];

        return array_values(array_filter($tools, static fn(mixed $tool): bool => $tool instanceof Tool));
    }

    /**
     * @param list<Tool> $tools
     * @return list<AiToolDefinition>
     */
    private function toToolDefinitions(array $tools): array
    {
        $definitions = [];
        foreach ($tools as $tool) {
            $parameters = $tool->getMetadataValue('parameters');
            if (!is_array($parameters)) {
                $parameters = $tool->getParameters() ?? ['type' => 'object', 'properties' => []];
            }
            $schema = [];
            foreach ($parameters as $key => $value) {
                $schema[(string) $key] = $value;
            }
            $definitions[] = new AiToolDefinition($tool->getName(), $tool->getDescription(), $schema);
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function recordThinking(array $raw): void
    {
        if (!$this->showThinking) {
            return;
        }
        $thinking = '';
        foreach (['thinking', 'reasoning'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $thinking = trim($raw[$key]);
                break;
            }
        }
        if ($thinking === '') {
            return;
        }

        $this->state->messages[] = [
            'role' => 'assistant',
            'content' => $thinking,
            'meta' => ['type' => 'provider_thinking', 'correlationId' => $this->state->correlationId],
        ];
        $this->state->emit('thinking', ['content' => $thinking]);
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $options
     */
    private function deferred(TextResult|ToolCallResult $result, array $raw, array $options): DeferredResult
    {
        return new DeferredResult(new PlainConverter($result), new InMemoryRawResult($raw), $options);
    }
}
