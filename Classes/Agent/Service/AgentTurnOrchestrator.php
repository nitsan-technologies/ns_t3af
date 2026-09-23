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
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * NL turn orchestrator: tool-calling loop with budgets, persona, and smart gating.
 *
 * @internal
 */
final readonly class AgentTurnOrchestrator implements AgentTurnRunnerInterface
{
    private const HISTORY_MESSAGE_LIMIT = 12;

    private const MAX_LOOP_ITERATIONS = 8;

    /**
     * Tools that may run on empty-LLM recovery when required args can be filled
     * from context and/or structural patterns in the user message (#uid, "query").
     *
     * @var list<string>
     */
    private const EMPTY_RECOVERY_TOOLS = [
        'pages_get',
        'pages_list',
        'content_list',
        'site_languages_list',
        'site_configuration_get',
        'explain_capabilities',
        'content_get',
        'content_delete',
        'pages_search',
        'content_search',
        'write_table',
    ];

    public function __construct(
        private AiToolCallingServiceInterface $toolCallingService,
        private AgentActionCatalogInterface $permittedActionProvider,
        private AgentToolDefinitionMapper $toolDefinitionMapper,
        private AgentToolShortlistService $toolShortlist,
        private AgentToolTurnExecutorInterface $toolTurnProcessor,
        private AgentSettingsService $agentSettings,
        private BrandContextResolver $brandContextResolver,
        private BrandContextAssembler $brandContextAssembler,
        private AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
        private AgentTranslator $translator,
        private AgentLanguageResolver $languageResolver,
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
                'content' => $this->translator->translate('agent.turn.noToolCalling'),
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
                'content' => $this->translator->translate('agent.turn.noExecutableTools'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId],
            ];
            $this->emit($emitEvent, 'message', ['message' => $message]);

            return [
                'messages' => [$message],
                'paused' => false,
                'pauseReason' => null,
            ];
        }

        $shortlistLimit = $this->agentSettings->getShortlistSize();
        $shortlistResult = $this->toolShortlist->shortlist(
            $userMessage,
            $context,
            $executableTools,
            $historyMessages,
            $shortlistLimit,
        );
        $shortlistedTools = $shortlistResult['tools'];
        $routingSource = $shortlistResult['routingSource'];
        $directTool = is_string($shortlistResult['directTool'] ?? null)
            ? trim((string) $shortlistResult['directTool'])
            : '';
        $primaryHit = is_string($shortlistResult['primaryHit'] ?? null)
            ? trim((string) $shortlistResult['primaryHit'])
            : '';

        // Embeddings ranked explain_capabilities #1 (or near) → skip the LLM round-trip.
        if ($directTool !== '' && $this->shortlistHasTool($shortlistedTools, $directTool)) {
            $this->emit($emitEvent, 'progress', [
                'status' => 'tool',
                'tool' => $directTool,
            ]);
            $toolBody = $body;
            $toolBody['arguments'] = [];
            $message = $this->toolTurnProcessor->execute(
                $directTool,
                $context,
                $toolBody,
                $user,
                $correlationId,
            );
            $message['meta']['directTool'] = $directTool;
            $message['meta']['routingSource'] = $routingSource;
            $message['meta']['shortlistedTools'] = array_map(
                static fn(array $tool): string => (string) ($tool['name'] ?? ''),
                $shortlistedTools,
            );
            $this->emit($emitEvent, 'message', ['message' => $message]);

            return [
                'messages' => [$message],
                'paused' => false,
                'pauseReason' => null,
            ];
        }

        $tools = $this->toolDefinitionMapper->mapExecutableTools($shortlistedTools);
        $retriedWithWidenedShortlist = false;

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

            $this->emit($emitEvent, 'progress', [
                'status' => 'llm',
                'iteration' => $iteration,
            ]);

            try {
                $response = $this->toolCallingService->completeWithTools($llmMessages, $tools, $options);
            } catch (\Throwable $exception) {
                $message = [
                    'role' => 'assistant',
                    'content' => $this->translator->translate('agent.turn.orchestratorFailed', [$exception->getMessage()]),
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
                if ($text === '') {
                    $recovery = $this->resolveEmptyTurnRecovery(
                        $primaryHit,
                        $shortlistedTools,
                        $userMessage,
                        $context,
                    );
                    if ($recovery !== null) {
                        $this->emit($emitEvent, 'progress', [
                            'status' => 'tool',
                            'tool' => $recovery['tool'],
                        ]);
                        $recovered = $this->recoverEmptyTurnWithPrimaryHit(
                            $recovery['tool'],
                            $recovery['arguments'],
                            $shortlistedTools,
                            $context,
                            $body,
                            $user,
                            $correlationId,
                            $routingSource,
                            $response->modelId,
                            $response->providerIdentifier,
                            array_values($trace),
                        );
                        if ($recovered !== null) {
                            $assistantMessages[] = $recovered;
                            $this->emit($emitEvent, 'message', ['message' => $recovered]);

                            return [
                                'messages' => $assistantMessages,
                                'paused' => false,
                                'pauseReason' => null,
                            ];
                        }
                    }
                }
                // Widen only when always-on recovery tools were not already shortlisted.
                if (
                    $text === ''
                    && !$retriedWithWidenedShortlist
                    && !$this->shortlistHasTool($shortlistedTools, 'explain_capabilities')
                    && $shortlistLimit < count($executableTools)
                ) {
                    $retriedWithWidenedShortlist = true;
                    $shortlistLimit = min(AgentToolShortlistService::WIDEN_SHORTLIST, count($executableTools));
                    $shortlistResult = $this->toolShortlist->shortlist(
                        $userMessage,
                        $context,
                        $executableTools,
                        $historyMessages,
                        $shortlistLimit,
                    );
                    $shortlistedTools = $shortlistResult['tools'];
                    $routingSource = $shortlistResult['routingSource'];
                    $primaryHit = is_string($shortlistResult['primaryHit'] ?? null)
                        ? trim((string) $shortlistResult['primaryHit'])
                        : $primaryHit;
                    $tools = $this->toolDefinitionMapper->mapExecutableTools($shortlistedTools);
                    continue;
                }
                $message = [
                    'role' => 'assistant',
                    'content' => $text !== ''
                        ? $text
                        : $this->buildEmptyModelReply($userMessage, $context, $executableTools, $shortlistedTools),
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
                        'routingSource' => $routingSource,
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

                $this->emit($emitEvent, 'progress', [
                    'status' => 'tool',
                    'tool' => $toolName,
                ]);

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
            'content' => $this->translator->translate('agent.turn.loopLimit'),
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
            $this->languageResolver->backendLanguageInstruction(),
            'Read tools run immediately. Write tools produce drafts that require explicit editor approval.',
            'Prefer concise answers grounded in tool results. Never claim a change was saved unless the editor applied a draft.',
            'When the user asks to create, update, translate, or generate content, prefer calling the most specific write tool instead of replying with text only.',
            'When the user asks what you can do, which tools are available, or how you can help, you MUST call explain_capabilities (do not answer with an empty message).',
            'Never respond with an empty message when tools are available — call a tool or write a short answer.',
            'Use pageId/pid/uid from context when a tool accepts a page or storage folder id.',
        ];

        if ($pageId > 0) {
            $languageId = isset($context['languageId']) ? (int) $context['languageId'] : null;
            $lines[] = $this->languageResolver->contentLanguageInstruction($pageId, $languageId);
        }

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
        unset($userMessage);
        $meta = $toolMessage['meta'];

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
            'content' => $this->translator->translate($key, [(string) $limit]),
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
     * Pick a recovery tool + args when the model returns empty.
     * Structural only: embedding primary hit, then shortlist fallbacks, with args
     * filled from #uid / "quoted" / context — never keyword routing of meaning.
     *
     * @param list<array<string, mixed>> $shortlistedTools
     * @param array<string, mixed> $context
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    private function resolveEmptyTurnRecovery(
        string $primaryHit,
        array $shortlistedTools,
        string $userMessage,
        array $context,
    ): ?array {
        // Structural create (e.g. "Add a headline Welcome") — write_table often misses the
        // embedding shortlist, so run before shortlist-gated candidates.
        $writeArgs = $this->extractWriteTableCreateArgument($userMessage, $context);
        if ($writeArgs !== null) {
            return ['tool' => 'write_table', 'arguments' => $writeArgs];
        }

        $shortlistNames = [];
        foreach ($shortlistedTools as $tool) {
            $name = trim((string) ($tool['name'] ?? ''));
            if ($name !== '') {
                $shortlistNames[] = $name;
            }
        }

        $candidates = [];
        // Message noun wins over embedding primary (pages_search vs content_search).
        $searchTool = $this->preferSearchToolFromMessage($userMessage, $shortlistNames);
        if ($searchTool !== null) {
            $candidates[] = $searchTool;
        }
        if ($primaryHit !== '' && !in_array($primaryHit, $candidates, true)) {
            $candidates[] = $primaryHit;
        }
        // Prefer actionable tools over always-on meta (explain_capabilities).
        foreach (['content_delete', 'content_get', 'write_table', 'pages_search', 'content_search', 'pages_get', 'content_list', 'pages_list', 'site_languages_list'] as $preferred) {
            if (in_array($preferred, $shortlistNames, true) && !in_array($preferred, $candidates, true)) {
                $candidates[] = $preferred;
            }
        }
        foreach ($shortlistNames as $name) {
            if ($name === 'explain_capabilities' || $name === 'ask_clarification') {
                continue;
            }
            if (!in_array($name, $candidates, true)) {
                $candidates[] = $name;
            }
        }
        // Last resort: capabilities only if it was the embedding primary hit.
        if ($primaryHit === 'explain_capabilities' && !in_array('explain_capabilities', $candidates, true)) {
            $candidates[] = 'explain_capabilities';
        }

        foreach ($candidates as $toolName) {
            if (!in_array($toolName, self::EMPTY_RECOVERY_TOOLS, true)) {
                continue;
            }
            if (!$this->shortlistHasTool($shortlistedTools, $toolName)) {
                continue;
            }
            $arguments = $this->buildRecoveryArguments($toolName, $userMessage, $context);
            if ($arguments === null) {
                continue;
            }

            return ['tool' => $toolName, 'arguments' => $arguments];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null null = cannot safely run this tool
     */
    private function buildRecoveryArguments(string $toolName, string $userMessage, array $context): ?array
    {
        $pageId = (int) ($context['pageId'] ?? 0);

        return match ($toolName) {
            'pages_get' => $pageId > 0 ? ['uid' => $pageId] : null,
            'pages_list',
            'content_list',
            'site_languages_list',
            'site_configuration_get',
            'explain_capabilities' => [],
            'content_get',
            'content_delete' => $this->extractUidArgument($userMessage, $context),
            'pages_search',
            'content_search' => $this->extractSearchArgument($userMessage),
            'write_table' => $this->extractWriteTableCreateArgument($userMessage, $context),
            default => null,
        };
    }

    /**
     * @param list<string> $shortlistNames
     */
    private function preferSearchToolFromMessage(string $userMessage, array $shortlistNames): ?string
    {
        $mentionsContent = preg_match('/\bcontent\b/i', $userMessage) === 1;
        $mentionsPages = preg_match('/\bpages?\b/i', $userMessage) === 1;
        if ($mentionsContent && !$mentionsPages && in_array('content_search', $shortlistNames, true)) {
            return 'content_search';
        }
        if ($mentionsPages && in_array('pages_search', $shortlistNames, true)) {
            return 'pages_search';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{uid: int}|null
     */
    private function extractUidArgument(string $userMessage, array $context): ?array
    {
        if (preg_match('/#\s*(\d+)\b/', $userMessage, $match) === 1) {
            return ['uid' => (int) $match[1]];
        }
        if (preg_match('/\b(?:uid|element|tt_content)\s*[:=]?\s*(\d+)\b/i', $userMessage, $match) === 1) {
            return ['uid' => (int) $match[1]];
        }
        $record = is_array($context['record'] ?? null) ? $context['record'] : null;
        if ($record !== null && ($record['table'] ?? '') === 'tt_content') {
            $uid = (int) ($record['uid'] ?? 0);
            if ($uid > 0) {
                return ['uid' => $uid];
            }
        }

        return null;
    }

    /**
     * @return array{search: string}|null
     */
    private function extractSearchArgument(string $userMessage): ?array
    {
        if (preg_match('/"([^"]+)"/', $userMessage, $match) === 1) {
            $query = trim($match[1]);

            return $query !== '' ? ['search' => $query] : null;
        }
        if (preg_match("/'([^']+)'/", $userMessage, $match) === 1) {
            $query = trim($match[1]);

            return $query !== '' ? ['search' => $query] : null;
        }
        // "Search content for Camino" / "Find pages named FAQ"
        if (preg_match(
            '/\b(?:search|find|list)\s+(?:content|pages?|records?)\s+(?:for|named|called)\s+(.+)$/iu',
            $userMessage,
            $match,
        ) === 1) {
            $query = trim($match[1], " \t\n\r\0\x0B.?!„“\"'");

            return $query !== '' ? ['search' => $query] : null;
        }
        if (preg_match('/\b(?:named|called)\s+(.+)$/iu', $userMessage, $match) === 1) {
            $query = trim($match[1], " \t\n\r\0\x0B.?!„“\"'");

            return $query !== '' ? ['search' => $query] : null;
        }
        if (preg_match('/\b(?:search|find)\s+for\s+(.+)$/iu', $userMessage, $match) === 1) {
            $query = trim($match[1], " \t\n\r\0\x0B.?!„“\"'");

            return $query !== '' ? ['search' => $query] : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{action: string, tableName: string, data: string}|null
     */
    private function extractWriteTableCreateArgument(string $userMessage, array $context): ?array
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        if ($pageId <= 0) {
            return null;
        }
        if (preg_match(
            '/\b(?:add|create|new)\s+(?:a\s+)?(?:headline|header|title)\s+["\']?(.+?)["\']?\s*$/iu',
            $userMessage,
            $match,
        ) !== 1) {
            return null;
        }
        $header = trim($match[1], " \t\n\r\0\x0B.?!„“\"'");
        if ($header === '') {
            return null;
        }

        return [
            'action' => 'create',
            'tableName' => 'tt_content',
            'data' => json_encode([
                'pid' => $pageId,
                'CType' => 'header',
                'header' => $header,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<array<string, mixed>> $shortlistedTools
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<mixed> $trace
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function recoverEmptyTurnWithPrimaryHit(
        string $toolName,
        array $arguments,
        array $shortlistedTools,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        string $routingSource,
        string $modelId,
        string $providerIdentifier,
        array $trace,
    ): ?array {
        $toolName = trim($toolName);
        if ($toolName === '') {
            return null;
        }
        // write_table structural create may not be in the embedding shortlist.
        if (
            !$this->shortlistHasTool($shortlistedTools, $toolName)
            && !($toolName === 'write_table' && ($arguments['action'] ?? '') === 'create')
        ) {
            return null;
        }

        $toolBody = $body;
        $toolBody['arguments'] = $arguments;
        $message = $this->toolTurnProcessor->execute(
            $toolName,
            $context,
            $toolBody,
            $user,
            $correlationId,
        );
        $message['meta']['emptyTurnRecovery'] = $toolName;
        $message['meta']['modelId'] = $modelId;
        $message['meta']['providerIdentifier'] = $providerIdentifier;
        $message['meta']['routingSource'] = $routingSource;
        $message['meta']['trace'] = $trace;
        $message['meta']['shortlistedTools'] = array_map(
            static fn(array $tool): string => (string) ($tool['name'] ?? ''),
            $shortlistedTools,
        );

        return $message;
    }

    /**
     * @param list<array<string, mixed>> $shortlistedTools
     */
    private function shortlistHasTool(array $shortlistedTools, string $toolName): bool
    {
        $needle = strtolower(trim($toolName));
        foreach ($shortlistedTools as $tool) {
            if (strtolower(trim((string) ($tool['name'] ?? ''))) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $executableTools
     * @param list<array<string, mixed>> $shortlistedTools
     */
    private function buildEmptyModelReply(
        string $userMessage,
        array $context,
        array $executableTools,
        array $shortlistedTools = [],
    ): string {
        unset($userMessage, $context, $executableTools);
        $names = [];
        foreach ($shortlistedTools as $tool) {
            $name = trim((string) ($tool['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
            if (count($names) >= 5) {
                break;
            }
        }
        if ($names === []) {
            return $this->translator->translate('agent.turn.emptyModelReply');
        }

        return $this->translator->translate('agent.turn.emptyModelReplyWithTools', [implode(', ', $names)]);
    }
}
