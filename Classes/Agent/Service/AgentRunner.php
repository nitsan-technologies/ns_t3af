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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentToolTurnExecutorInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentTurnRunnerInterface;
use NITSAN\NsT3AF\Agent\Runtime\AgentToolRuntime;
use NITSAN\NsT3AF\Agent\Runtime\AgentTurnState;
use NITSAN\NsT3AF\Agent\Runtime\GovernedPlatform;
use NITSAN\NsT3AF\Agent\Runtime\T3afToolbox;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Runs one natural-language turn of the AI Agent with the Symfony AI Agent loop.
 *
 * - {@see GovernedPlatform}: every model round goes through AiToolCallingService (governance, credits, logs).
 * - {@see AgentCoreToolSet}: the tools offered at the start (core + current module + recently used).
 * - {@see T3afToolbox}: find_tools for everything else, argument checks, budgets and pauses.
 * - The loop ends when the model answers with text, when a tool message needs the editor
 *   (draft review, blocked, budget), when AI Access cancels the request, or after
 *   {@see self::MAX_MODEL_ROUNDS} rounds with tool calls.
 *
 * @internal
 */
final readonly class AgentRunner implements AgentTurnRunnerInterface
{
    public const MAX_MODEL_ROUNDS = 8;

    /** Tools added per turn by searching the editor's request. */
    public const REQUEST_TOOLS = 4;

    /** A message shorter than this ("Yes", "do it") is searched together with the previous request. */
    private const SHORT_REPLY_CHARS = 25;

    private const AGENT_MODEL = 't3af-provider';

    public function __construct(
        private AiToolCallingServiceInterface $toolCallingService,
        private AgentActionCatalogInterface $actionCatalog,
        private AgentToolDefinitionMapper $toolDefinitionMapper,
        private AgentCoreToolSet $coreToolSet,
        private AgentToolSearch $toolSearch,
        private AgentToolArgumentValidator $argumentValidator,
        private AgentToolTurnExecutorInterface $toolExecutor,
        private AgentSettingsService $agentSettings,
        private AgentPromptBuilder $promptBuilder,
        private AgentPausePolicy $pausePolicy,
        private AgentTranslator $translator,
    ) {}

    public function runTurn(
        string $userMessage,
        array $historyMessages,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        ?callable $emitEvent = null,
    ): array {
        $pageId = (int) ($context['pageId'] ?? 0);
        if (!$this->toolCallingService->supportsToolCalling(self::providerFromBody($body), $pageId > 0 ? $pageId : null)) {
            return $this->single($emitEvent, $this->info('agent.turn.noToolCalling', $correlationId, ['degraded' => true]));
        }

        $catalog = $this->actionCatalog->buildCatalog();
        $executableTools = $catalog['executable'];
        if ($executableTools === []) {
            return $this->single($emitEvent, $this->info('agent.turn.noExecutableTools', $correlationId));
        }

        $requestQuery = self::requestQuery($userMessage, $historyMessages);
        $offeredTools = $this->coreToolSet->forTurn($executableTools, $context, $historyMessages, $requestQuery);
        $offeredTools = $this->withToolsForTheRequest($offeredTools, $executableTools, $userMessage, $historyMessages);

        $severities = [];
        foreach ([...$catalog['executable'], ...$catalog['locked']] as $tool) {
            $severities[(string) ($tool['name'] ?? '')] = (string) ($tool['severity'] ?? ToolSeverity::Write->value);
        }

        [$state, $finalText] = $this->runAgent($userMessage, $historyMessages, $context, $body, $user, $correlationId, $emitEvent, $offeredTools, $executableTools, $severities);

        if ($state->isCancelled()) {
            $state->addMessage([
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.governanceBlocked', [$state->cancelledReason() !== '' ? $state->cancelledReason() : '-']),
                'meta' => ['type' => 'governance_blocked', 'correlationId' => $correlationId],
            ]);

            return ['messages' => $state->messages, 'paused' => false, 'pauseReason' => null];
        }

        if ($state->isPaused()) {
            return ['messages' => $state->messages, 'paused' => true, 'pauseReason' => $state->pauseReason()];
        }

        if ($state->failed || ($finalText === '' && $state->executedTools !== [])) {
            return ['messages' => $state->messages, 'paused' => false, 'pauseReason' => null];
        }


        if ($finalText !== '') {
            $state->emit('delta', ['content' => $finalText]);
        }
        $state->addMessage([
            'role' => 'assistant',
            'content' => $finalText !== '' ? $finalText : $this->translator->translate('agent.turn.emptyModelReply'),
            'meta' => [
                'type' => 'nl_reply',
                'correlationId' => $correlationId,
                'modelId' => $state->modelId,
                'providerIdentifier' => $state->providerIdentifier,
                // The steps keep their own responses; here only what ran, to keep the row small.
                'trace' => self::traceWithoutResponses($state->trace),
                'offeredTools' => $this->toolNames($offeredTools),
                'foundTools' => $state->foundTools,
                'runner' => 'symfony-agent',
            ],
        ]);

        return ['messages' => $state->messages, 'paused' => false, 'pauseReason' => null];
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     * @param list<array<string, mixed>> $offeredTools
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, string> $severities
     * @return array{AgentTurnState, string} state and the model's final text
     */
    private function runAgent(
        string $userMessage,
        array $historyMessages,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        ?callable $emitEvent,
        array $offeredTools,
        array $executableTools,
        array $severities,
    ): array {
        $finalText = '';
        $state = new AgentTurnState($correlationId, $emitEvent);
        $pageId = (int) ($context['pageId'] ?? 0);
        $providerIdentifier = self::providerFromBody($body);

        $toolbox = new T3afToolbox(
            $this->toolDefinitionMapper->mapExecutableTools($offeredTools),
            $executableTools,
            $severities,
            $state,
            new AgentToolRuntime(
                $this->toolExecutor,
                $this->pausePolicy,
                $this->translator,
                $this->toolSearch,
                $this->toolDefinitionMapper,
                $this->argumentValidator,
                $context,
                $body,
                $user,
                $this->agentSettings->getMaxReadToolsPerTurn(),
                $this->agentSettings->getMaxWriteDraftsPerTurn(),
            ),
        );

        $platform = new GovernedPlatform(
            $this->toolCallingService,
            $state,
            static fn(array $messages): AiOptions => new AiOptions(
                pageId: $pageId > 0 ? $pageId : null,
                providerIdentifier: $providerIdentifier,
                extensionKey: 'ns_t3af',
                featureKey: 'agent.nl_turn',
                featureLabel: 'AI Agent NL turn',
                requestSource: 'backend_module',
                extra: ['brandContextScope' => 'agent', 'messages' => $messages],
            ),
            $toolbox,
            $this->agentSettings->isProviderThinkingVisible(),
        );

        $events = new EventDispatcher();
        $events->addListener(ToolCallsExecuted::class, static function (ToolCallsExecuted $event) use ($state): void {
            if ($state->isPaused()) {
                $event->setResult(new TextResult(''));
            }
        });

        $agent = new Agent(
            $platform,
            self::AGENT_MODEL,
            toolbox: $toolbox,
            maxToolCalls: self::MAX_MODEL_ROUNDS,
            eventDispatcher: $events,
        );

        try {
            $agentResult = $agent->call($this->buildMessages($userMessage, $historyMessages, $context))->getResult();
            $content = $agentResult->getContent();
            $finalText = is_string($content) ? trim($content) : '';
        } catch (MaxIterationsExceededException) {
            $state->addMessage([
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.loopLimit'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId, 'trace' => self::traceWithoutResponses($state->trace), 'orchestratorPause' => true],
            ]);
            $state->pause('loop_limit');
        } catch (\Throwable $exception) {
            $state->addMessage([
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.orchestratorFailed', [$exception->getMessage()]),
                'meta' => ['type' => 'error', 'correlationId' => $correlationId, 'degraded' => true],
            ]);
            $state->failed = true;
        }

        return [$state, $finalText];
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @param array<string, mixed> $context
     */
    private function buildMessages(string $userMessage, array $historyMessages, array $context): MessageBag
    {
        $bag = new MessageBag();
        $system = $this->promptBuilder->buildSystemPrompt($context);
        if ($system !== '') {
            $bag->add(Message::forSystem($system));
        }
        foreach ($this->promptBuilder->buildHistory($historyMessages, (int) ($context['pageId'] ?? 0), $this->agentSettings->getHistoryTokenBudget() * 4) as $entry) {
            $bag->add($entry['role'] === 'assistant' ? Message::ofAssistant($entry['content']) : Message::ofUser($entry['content']));
        }
        $bag->add(Message::ofUser($userMessage));

        return $bag;
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     * @param array{role: string, content: string, meta: array<string, mixed>} $message
     * @return array{messages: list<array{role: string, content: string, meta: array<string, mixed>}>, paused: bool, pauseReason: string|null}
     */
    private function single(?callable $emitEvent, array $message): array
    {
        if ($emitEvent !== null) {
            $emitEvent('message', ['message' => $message]);
        }

        return ['messages' => [$message], 'paused' => false, 'pauseReason' => null];
    }

    /**
     * @param array<string, mixed> $extraMeta
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function info(string $key, string $correlationId, array $extraMeta = []): array
    {
        return [
            'role' => 'assistant',
            'content' => $this->translator->translate($key),
            'meta' => ['type' => 'info', 'correlationId' => $correlationId, ...$extraMeta],
        ];
    }

    /**
     * Adds the tools that fit the request (e.g. "create a page" → the create tool), so the model
     * can act right away instead of reading around or asking "Shall I proceed?" without a tool.
     *
     * A short reply ("Yes", "do it") or a continuation after a card is searched together with
     * the editor's previous request and the agent's last reply.
     *
     * @param list<array<string, mixed>> $offeredTools
     * @param list<array<string, mixed>> $executableTools
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array<string, mixed>>
     */
    private function withToolsForTheRequest(array $offeredTools, array $executableTools, string $userMessage, array $historyMessages): array
    {
        $query = self::requestQuery($userMessage, $historyMessages);
        if ($query === '') {
            return $offeredTools;
        }

        $createContent = AgentCoreToolSet::isCreateContentRequest($query);
        if ($createContent) {
            $offeredTools = array_values(array_filter(
                $offeredTools,
                static fn(array $tool): bool => (string) ($tool['name'] ?? '') !== 'content_delete',
            ));
        }

        $offeredNames = array_flip($this->toolNames($offeredTools));
        $candidates = array_values(array_filter(
            $executableTools,
            static fn(array $tool): bool => !isset($offeredNames[(string) ($tool['name'] ?? '')])
                && (!$createContent || (string) ($tool['name'] ?? '') !== 'content_delete'),
        ));
        try {
            $found = $this->toolSearch->search($query, $candidates, self::REQUEST_TOOLS)['tools'];
        } catch (\Throwable) {
            $found = [];
        }

        if ($createContent) {
            $found = [...self::createContentTools($executableTools, $offeredNames), ...$found];
        }

        $byName = [];
        foreach ([...$offeredTools, ...$found] as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name !== '') {
                $byName[$name] = $tool;
            }
        }
        ksort($byName);

        return array_values($byName);
    }

    /**
     * Prefer dedicated create-content tools even when the module tool cap sliced them out.
     *
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function createContentTools(array $executableTools, array $alreadyOffered = []): array
    {
        $picked = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '' || isset($alreadyOffered[$name]) || isset($picked[$name])) {
                continue;
            }
            if ($name === 't3ai_create_content_element' || $name === 'write_table') {
                $picked[$name] = $tool;
                continue;
            }
            $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
            $verbs = array_map('strtolower', array_map('strval', is_array($intent['verbs'] ?? null) ? $intent['verbs'] : []));
            $nouns = array_map('strtolower', array_map('strval', is_array($intent['nouns'] ?? null) ? $intent['nouns'] : []));
            $category = strtolower((string) ($intent['category'] ?? ''));
            if (
                $category === 'content'
                && array_intersect($verbs, ['create', 'write', 'add', 'generate']) !== []
                && array_intersect($nouns, ['content', 'element', 'block', 'tt_content']) !== []
            ) {
                $picked[$name] = $tool;
            }
        }

        return array_values($picked);
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function requestQuery(string $userMessage, array $historyMessages): string
    {
        $message = trim($userMessage);
        $isContinuation = str_starts_with($message, '[The editor ');
        if (!$isContinuation && mb_strlen($message) >= self::SHORT_REPLY_CHARS) {
            return $message;
        }

        $earlier = [];
        for ($i = count($historyMessages) - 1; $i >= 0 && count($earlier) < 2; --$i) {
            $entry = $historyMessages[$i];
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $content = trim((string) ($entry['content'] ?? ''));
            if ($content === '' || ($meta['hidden'] ?? false) === true) {
                continue;
            }
            $isRequest = ($entry['role'] ?? '') === 'user';
            $isReply = ($entry['role'] ?? '') === 'assistant' && ($meta['type'] ?? '') === 'nl_reply' && $earlier === [];
            if ($isRequest || $isReply) {
                array_unshift($earlier, mb_substr($content, 0, 300));
            }
        }
        if (!$isContinuation) {
            $earlier[] = $message;
        }

        return trim(implode("\n", $earlier));
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        return array_values(array_filter(array_map(
            static fn(array $tool): string => trim((string) ($tool['name'] ?? '')),
            $tools,
        )));
    }

    /**
     * Provider of the conversation ("provider" in the request body; empty or "default" = default provider).
     *
     * @param array<string, mixed> $body
     */
    public static function providerFromBody(array $body): ?string
    {
        $provider = trim((string) ($body['provider'] ?? ''));

        return $provider === '' || $provider === 'default' ? null : $provider;
    }

    /**
     * @param list<mixed> $trace
     * @return list<mixed>
     */
    private static function traceWithoutResponses(array $trace): array
    {
        return array_map(
            static function (mixed $entry): mixed {
                if (is_array($entry)) {
                    unset($entry['response']);
                }

                return $entry;
            },
            $trace,
        );
    }
}
