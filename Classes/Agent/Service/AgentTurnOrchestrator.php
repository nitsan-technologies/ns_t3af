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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * NL turn orchestrator: tool-calling loop with budgets, persona, and smart gating.
 *
 * @internal
 */
final readonly class AgentTurnOrchestrator
{
    private const HISTORY_MESSAGE_LIMIT = 12;

    private const MAX_LOOP_ITERATIONS = 8;

    public function __construct(
        private AiToolCallingServiceInterface $toolCallingService,
        private PermittedActionProvider $permittedActionProvider,
        private AgentToolDefinitionMapper $toolDefinitionMapper,
        private AgentToolRetriever $toolRetriever,
        private AgentToolTurnProcessor $toolTurnProcessor,
        private AgentSettingsService $agentSettings,
        private BrandContextResolver $brandContextResolver,
        private BrandContextAssembler $brandContextAssembler,
        private AgentFieldExtractor $fieldExtractor,
        private AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
    ) {}

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param callable(string, array<string, mixed>): void|null $emitEvent SSE emitter (event name, payload)
     * @return array{
     *   messages: list<array{role: string, content: string, meta: array<string, mixed>}>,
     *   paused: bool,
     *   pauseReason: string|null
     * }
     */
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
        if (!$this->toolCallingService->supportsToolCalling(null, $pageId > 0 ? $pageId : null)) {
            $message = [
                'role' => 'assistant',
                'content' => $this->translate('agent.turn.noToolCalling'),
                'meta' => [
                    'type' => 'info',
                    'correlationId' => $correlationId,
                    'degraded' => true,
                ],
            ];
            $this->emit($emitEvent, 'message', ['message' => $message]);

            return [
                'messages' => [$message],
                'paused' => false,
                'pauseReason' => null,
            ];
        }

        $catalog = $this->permittedActionProvider->buildCatalog();
        $executableTools = $catalog['executable'];
        if ($executableTools === []) {
            $message = [
                'role' => 'assistant',
                'content' => $this->translate('agent.turn.noExecutableTools'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId],
            ];
            $this->emit($emitEvent, 'message', ['message' => $message]);

            return [
                'messages' => [$message],
                'paused' => false,
                'pauseReason' => null,
            ];
        }

        $shortlistLimit = AgentToolRetriever::DEFAULT_SHORTLIST;
        $shortlistedTools = $this->toolRetriever->shortlist(
            $userMessage,
            $context,
            $executableTools,
            $shortlistLimit,
        );
        $tools = $this->toolDefinitionMapper->mapExecutableTools($shortlistedTools);
        $retriedWithWidenedShortlist = false;

        $preLlmAutoInvoke = $this->toolRetriever->buildAutoInvocation(
            $userMessage,
            $context,
            $executableTools,
        );
        if ($preLlmAutoInvoke !== null) {
            return $this->executeAutoInvocation(
                $preLlmAutoInvoke,
                $context,
                $body,
                $user,
                $correlationId,
                $emitEvent,
            );
        }

        $maxReads = $this->agentSettings->getMaxReadToolsPerTurn();
        $maxWriteDrafts = $this->agentSettings->getMaxWriteDraftsPerTurn();
        $readCount = 0;
        $writeDraftCount = 0;
        $assistantMessages = [];
        $llmMessages = $this->buildLlmMessages($userMessage, $historyMessages, $context);
        $trace = [];

        for ($iteration = 0; $iteration < self::MAX_LOOP_ITERATIONS; ++$iteration) {
            $options = new AiOptions(
                pageId: $pageId > 0 ? $pageId : null,
                extensionKey: 'ns_t3af',
                featureKey: 'agent.nl_turn',
                featureLabel: 'AI Agent NL turn',
                requestSource: 'backend_module',
                extra: [
                    'brandContextScope' => 'agent',
                    'messages' => $llmMessages,
                ],
            );

            try {
                $response = $this->toolCallingService->completeWithTools($llmMessages, $tools, $options);
            } catch (\Throwable $exception) {
                $message = [
                    'role' => 'assistant',
                    'content' => $this->translate('agent.turn.orchestratorFailed', [$exception->getMessage()]),
                    'meta' => [
                        'type' => 'error',
                        'correlationId' => $correlationId,
                        'degraded' => true,
                    ],
                ];
                $assistantMessages[] = $message;
                $this->emit($emitEvent, 'message', ['message' => $message]);

                return [
                    'messages' => $assistantMessages,
                    'paused' => false,
                    'pauseReason' => null,
                ];
            }

            $thinking = $this->extractThinking($response->raw);
            if ($thinking !== '' && $this->agentSettings->isProviderThinkingVisible()) {
                $thinkingMessage = [
                    'role' => 'assistant',
                    'content' => $thinking,
                    'meta' => [
                        'type' => 'provider_thinking',
                        'correlationId' => $correlationId,
                    ],
                ];
                $assistantMessages[] = $thinkingMessage;
                $this->emit($emitEvent, 'thinking', ['content' => $thinking]);
            }

            if ($response->toolCalls === []) {
                $text = trim($response->content);
                if ($text === '' && $assistantMessages !== []) {
                    break;
                }
                if ($text === '' && !$retriedWithWidenedShortlist && $shortlistLimit < count($executableTools)) {
                    $retriedWithWidenedShortlist = true;
                    $shortlistLimit = min(AgentToolRetriever::WIDEN_SHORTLIST, count($executableTools));
                    $shortlistedTools = $this->toolRetriever->shortlist(
                        $userMessage,
                        $context,
                        $executableTools,
                        $shortlistLimit,
                    );
                    $tools = $this->toolDefinitionMapper->mapExecutableTools($shortlistedTools);
                    continue;
                }
                $fallbackAutoInvoke = $this->toolRetriever->buildAutoInvocation(
                    $userMessage,
                    $context,
                    $executableTools,
                    afterLlmFailure: true,
                );
                if ($fallbackAutoInvoke !== null) {
                    return $this->executeAutoInvocation(
                        $fallbackAutoInvoke,
                        $context,
                        $body,
                        $user,
                        $correlationId,
                        $emitEvent,
                    );
                }
                $message = [
                    'role' => 'assistant',
                    'content' => $text !== ''
                        ? $text
                        : $this->buildEmptyModelReply($userMessage, $context, $executableTools),
                    'meta' => [
                        'type' => 'nl_reply',
                        'correlationId' => $correlationId,
                        'modelId' => $response->modelId,
                        'providerIdentifier' => $response->providerIdentifier,
                        'trace' => $trace,
                        'shortlistedTools' => array_map(
                            static fn(array $tool): string => (string) ($tool['name'] ?? ''),
                            $shortlistedTools,
                        ),
                    ],
                ];
                $assistantMessages[] = $message;
                $this->emitDelta($emitEvent, $text);
                $this->emit($emitEvent, 'message', ['message' => $message]);

                return [
                    'messages' => $assistantMessages,
                    'paused' => false,
                    'pauseReason' => null,
                ];
            }

            foreach ($response->toolCalls as $toolCall) {
                $toolName = $toolCall->name;
                $severity = $this->resolveToolSeverity($catalog, $toolName);
                if ($severity === ToolSeverity::Read->value) {
                    if ($readCount >= $maxReads) {
                        $message = $this->budgetExceededMessage($correlationId, 'read', $maxReads);
                        $assistantMessages[] = $message;
                        $this->emit($emitEvent, 'message', ['message' => $message]);

                        return [
                            'messages' => $assistantMessages,
                            'paused' => true,
                            'pauseReason' => 'read_budget',
                        ];
                    }
                    ++$readCount;
                } elseif ($severity === ToolSeverity::Write->value || $severity === ToolSeverity::Destructive->value) {
                    if ($writeDraftCount >= $maxWriteDrafts) {
                        $message = $this->budgetExceededMessage($correlationId, 'write', $maxWriteDrafts);
                        $assistantMessages[] = $message;
                        $this->emit($emitEvent, 'message', ['message' => $message]);

                        return [
                            'messages' => $assistantMessages,
                            'paused' => true,
                            'pauseReason' => 'write_budget',
                        ];
                    }
                    ++$writeDraftCount;
                }

                $toolBody = $body;
                $toolBody['arguments'] = $toolCall->arguments;
                $toolMessage = $this->toolTurnProcessor->execute(
                    $toolName,
                    $context,
                    $toolBody,
                    $user,
                    $correlationId,
                );

                if (isset($toolMessage['meta']['trace']) && is_array($toolMessage['meta']['trace'])) {
                    $trace = array_merge($trace, $toolMessage['meta']['trace']);
                }

                $assistantMessages[] = $toolMessage;
                $this->emit($emitEvent, 'message', ['message' => $toolMessage]);

                $pause = $this->shouldPauseAfterTool($toolMessage);
                if ($pause['pause']) {
                    return [
                        'messages' => $assistantMessages,
                        'paused' => true,
                        'pauseReason' => $pause['reason'],
                    ];
                }

                $toolResultText = $this->summarizeToolResultForLlm($userMessage, $toolMessage);
                $llmMessages[] = [
                    'role' => 'assistant',
                    'content' => $response->content !== '' ? $response->content : null,
                    'tool_calls' => [[
                        'id' => $toolCall->id,
                        'name' => $toolCall->name,
                        'arguments' => $toolCall->arguments,
                    ]],
                ];
                $llmMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'content' => $toolResultText,
                ];
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => $this->translate('agent.turn.loopLimit'),
            'meta' => [
                'type' => 'info',
                'correlationId' => $correlationId,
                'trace' => $trace,
            ],
        ];
        $assistantMessages[] = $message;
        $this->emit($emitEvent, 'message', ['message' => $message]);

        return [
            'messages' => $assistantMessages,
            'paused' => true,
            'pauseReason' => 'loop_limit',
        ];
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildLlmMessages(string $userMessage, array $historyMessages, array $context): array
    {
        $messages = [];
        $system = $this->buildSystemPrompt($context);
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $scoped = array_slice($historyMessages, -self::HISTORY_MESSAGE_LIMIT);
        foreach ($scoped as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = (string) ($entry['role'] ?? 'user');
            if (!in_array($role, ['user', 'assistant', 'system'], true)) {
                continue;
            }
            $content = $this->historyContentForLlm($entry);
            if ($content === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildSystemPrompt(array $context): string
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $profile = $this->brandContextResolver->resolveDefaultForPageId($pageId > 0 ? $pageId : null);
        $persona = $profile !== null ? $this->brandContextAssembler->assemble($profile) : '';

        $lines = [
            'You are the TYPO3 backend AI Agent. Use the provided tools to answer questions and prepare changes.',
            'Read tools run immediately. Write tools produce drafts that require explicit editor approval.',
            'Prefer concise answers grounded in tool results. Never claim a change was saved unless the editor applied a draft.',
            'When the user asks to create, update, translate, or generate content, prefer calling the most specific write tool instead of replying with text only.',
            'Use pageId/pid/uid from context when a tool accepts a page or storage folder id.',
        ];

        if ($persona !== '') {
            $lines[] = 'Brand context (persona):';
            $lines[] = $persona;
        }

        $module = trim((string) ($context['module'] ?? ''));
        if ($module !== '') {
            $lines[] = 'Current backend module: ' . $module;
        }
        if ($pageId > 0) {
            $lines[] = 'Current page id: ' . $pageId;
        }
        $record = is_array($context['record'] ?? null) ? $context['record'] : null;
        if ($record !== null) {
            $lines[] = 'Focused record: ' . ($record['table'] ?? '') . ':' . ($record['uid'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function historyContentForLlm(array $entry): string
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        if (isset($meta['llmSummary']) && is_string($meta['llmSummary']) && trim($meta['llmSummary']) !== '') {
            return trim($meta['llmSummary']);
        }
        if (isset($meta['summary']) && is_string($meta['summary']) && trim($meta['summary']) !== '') {
            return trim($meta['summary']);
        }

        return trim((string) ($entry['content'] ?? ''));
    }

    /**
     * @param array{role: string, content: string, meta: array<string, mixed>} $toolMessage
     */
    private function summarizeToolResultForLlm(string $userMessage, array $toolMessage): string
    {
        $meta = $toolMessage['meta'];
        $toolName = (string) ($meta['tool'] ?? '');
        $raw = $meta['rawResult'] ?? null;
        if ($raw !== null && ($meta['type'] ?? '') === 'tool_result') {
            $extracted = $this->fieldExtractor->extract($userMessage, $toolName, $raw);
            if ($extracted['summary'] !== '') {
                return $extracted['summary'];
            }
        }

        if (isset($meta['llmSummary']) && is_string($meta['llmSummary']) && $meta['llmSummary'] !== '') {
            return $meta['llmSummary'];
        }
        if (isset($meta['summary']) && is_string($meta['summary']) && $meta['summary'] !== '') {
            return $meta['summary'];
        }

        return trim((string) ($toolMessage['content'] ?? ''));
    }

    /**
     * @param array{role: string, content: string, meta: array<string, mixed>} $toolMessage
     * @return array{pause: bool, reason: string|null}
     */
    private function shouldPauseAfterTool(array $toolMessage): array
    {
        $meta = $toolMessage['meta'];
        if (($meta['orchestratorPause'] ?? false) === true) {
            $type = (string) ($meta['type'] ?? '');
            if ($type === 'inline_draft') {
                $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : [];
                /** @var list<array<string, mixed>> $fields */
                $fields = is_array($draft['fields'] ?? null)
                    ? array_values(array_filter($draft['fields'], 'is_array'))
                    : [];
                $severity = (string) ($meta['severity'] ?? '');
                $lowRisk = $this->draftUsesOnlyLowRiskFields($fields);
                if ($severity !== ToolSeverity::Destructive->value && $lowRisk) {
                    return ['pause' => false, 'reason' => null];
                }

                return ['pause' => true, 'reason' => 'draft_review'];
            }

            return ['pause' => true, 'reason' => (string) ($meta['type'] ?? 'blocked')];
        }

        return ['pause' => false, 'reason' => null];
    }

    /**
     * @param list<array<string, mixed>> $draftFields
     */
    private function draftUsesOnlyLowRiskFields(array $draftFields): bool
    {
        if ($draftFields === []) {
            return false;
        }

        foreach ($draftFields as $field) {
            if (!is_array($field)) {
                return false;
            }
            $table = (string) ($field['table'] ?? '');
            $name = (string) ($field['field'] ?? $field['key'] ?? '');
            if (!$this->lowRiskFieldMatrix->isSafeField($table, $name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     */
    private function resolveToolSeverity(array $catalog, string $toolName): string
    {
        $needle = strtolower(trim($toolName));
        foreach ([$catalog['executable'], $catalog['locked']] as $group) {
            foreach ($group as $tool) {
                if (strtolower((string) ($tool['name'] ?? '')) === $needle) {
                    return (string) ($tool['severity'] ?? ToolSeverity::Read->value);
                }
            }
        }

        return ToolSeverity::Read->value;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function extractThinking(array $raw): string
    {
        if (isset($raw['thinking']) && is_string($raw['thinking'])) {
            return trim($raw['thinking']);
        }
        if (isset($raw['reasoning']) && is_string($raw['reasoning'])) {
            return trim($raw['reasoning']);
        }

        return '';
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function budgetExceededMessage(string $correlationId, string $kind, int $limit): array
    {
        $key = $kind === 'read'
            ? 'agent.turn.readBudgetExceeded'
            : 'agent.turn.writeBudgetExceeded';

        return [
            'role' => 'assistant',
            'content' => $this->translate($key, [(string) $limit]),
            'meta' => [
                'type' => 'budget_exceeded',
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
                'budgetKind' => $kind,
            ],
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     * @param array<string, mixed> $payload
     */
    private function emit(?callable $emitEvent, string $event, array $payload): void
    {
        if ($emitEvent === null) {
            return;
        }
        $emitEvent($event, $payload);
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     */
    private function emitDelta(?callable $emitEvent, string $content): void
    {
        if ($emitEvent === null || $content === '') {
            return;
        }
        $emitEvent('delta', ['content' => $content]);
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key;
        $value = $languageService instanceof LanguageService
            ? (string) $languageService->sL($label)
            : $key;

        if ($value === '' || $value === $label) {
            $value = $key;
        }

        if ($arguments === []) {
            return $value;
        }

        return sprintf($value, ...array_map(static fn(int|string $argument): string => (string) $argument, $arguments));
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $executableTools
     */
    private function buildEmptyModelReply(string $userMessage, array $context, array $executableTools): string
    {
        $suggestions = $this->toolRetriever->topToolNames(
            $userMessage,
            $context,
            $executableTools,
            5,
        );
        if ($suggestions === []) {
            return $this->translate('agent.turn.emptyModelReply');
        }

        return $this->translate('agent.turn.emptyModelReplyWithTools', [implode(', ', $suggestions)]);
    }

    /**
     * @param array{tool: string, arguments: array<string, mixed>} $autoInvoke
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return array{
     *   messages: list<array{role: string, content: string, meta: array<string, mixed>}>,
     *   paused: bool,
     *   pauseReason: string|null
     * }
     */
    private function executeAutoInvocation(
        array $autoInvoke,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        ?callable $emitEvent,
    ): array {
        $toolBody = $body;
        $toolBody['arguments'] = $autoInvoke['arguments'];
        $toolMessage = $this->toolTurnProcessor->execute(
            $autoInvoke['tool'],
            $context,
            $toolBody,
            $user,
            $correlationId,
        );
        $toolMessage['meta']['autoInvoked'] = true;
        $toolMessage['meta']['retrievalRouted'] = true;
        $this->emit($emitEvent, 'message', ['message' => $toolMessage]);

        $pause = $this->shouldPauseAfterTool($toolMessage);

        return [
            'messages' => [$toolMessage],
            'paused' => $pause['pause'],
            'pauseReason' => $pause['reason'],
        ];
    }
}
