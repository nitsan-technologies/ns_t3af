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
use NITSAN\NsT3AF\Agent\PremiumCatalog\PremiumCatalogProvider;
use NITSAN\NsT3AF\Agent\Runtime\AgentToolRuntime;
use NITSAN\NsT3AF\Agent\Runtime\AgentTurnState;
use NITSAN\NsT3AF\Agent\Runtime\GovernedPlatform;
use NITSAN\NsT3AF\Agent\Runtime\T3afToolbox;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\CreditsContentRemovedException;
use NITSAN\NsT3AF\Credits\Exception\InsufficientCreditsException;
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
        private PremiumCatalogProvider $premiumCatalog,
        private AgentEntitlementExplanation $entitlementExplanation,
        private ?AgentProviderOptions $providerOptions = null,
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
        // A single, standalone premium-capability request (e.g. "translate this page" without
        // ns_t3ai) is answered directly — a capable model can otherwise fake it with generic
        // tools instead of discovering there is no real tool for it. Skipped on a continuation
        // (confirm/decline of an in-progress draft, not a fresh request) and on anything that
        // reads as a multi-step ask, so the model still handles those normally.
        $continuation = is_array($body['continuation'] ?? null) ? $body['continuation'] : [];
        if ($continuation === []) {
            $premiumMatch = $this->premiumCatalog->findStandaloneMatch($userMessage);
            if ($premiumMatch !== null) {
                return $this->single($emitEvent, [
                    'role' => 'assistant',
                    'content' => $this->entitlementExplanation->buildNotPurchasedMessage($premiumMatch, $user),
                    'meta' => ['type' => 'not_purchased', 'correlationId' => $correlationId],
                ]);
            }
        }

        $pageId = (int) ($context['pageId'] ?? 0);
        if (!$this->toolCallingService->supportsToolCalling(self::providerFromBody($body), $pageId > 0 ? $pageId : null)) {
            // "Nothing configured yet" is a different problem from "this provider cannot run tools".
            $key = $this->providerOptions instanceof AgentProviderOptions && !$this->providerOptions->hasConfiguredProvider($pageId)
                ? 'agent.turn.noProvider'
                : 'agent.turn.noToolCalling';

            return $this->single($emitEvent, $this->info($key, $correlationId, ['degraded' => true]));
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
        $finalText = AgentPromptBuilder::collapseRepeatedReply($finalText);

        // Drop a card the editor already declined, and a second copy of a create that is still waiting.
        $state->messages = self::withoutRepeatedDeclinedDrafts($state->messages, $historyMessages);

        $state->attachPendingPlan();

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

        // Never surface internal draft-history notes as the editor-facing reply.
        if (AgentPromptBuilder::isCardHistoryEcho($finalText)) {
            $finalText = '';
        }

        $permissionRefused = self::turnWasRefusedByPermissions(
            $state->messages,
            $this->translator->translate('agent.tool.permissionDenied'),
        );

        $workPlan = $state->plan !== [] ? $state->plan : self::planCarriedIntoTurn($userMessage, $historyMessages);
        // The history holds the conversation *before* this message, so its newest user message is the
        // previous request: gating on it re-opened an old request's steps on every later question.
        $requestForGate = self::requestForGate($userMessage, $historyMessages);
        if (
            $finalText !== ''
            && !$permissionRefused
            && AgentPromptBuilder::hasBlockingRemainingWork($historyMessages, $workPlan, $requestForGate)
        ) {
            $finalText = '';
        }

        if ($finalText !== '') {
            $state->emit('delta', ['content' => $finalText]);
        }
        $nlMeta = [
            'type' => 'nl_reply',
            'correlationId' => $correlationId,
            'modelId' => $state->modelId,
            'providerIdentifier' => $state->providerIdentifier,
            'trace' => self::traceWithoutResponses($state->trace),
            'offeredTools' => $this->toolNames($offeredTools),
            'foundTools' => $state->foundTools,
            'runner' => 'symfony-agent',
        ];
        $effective = AgentRequestChecklist::effectivePlan($historyMessages, $workPlan, $requestForGate);
        if ($effective !== []) {
            $nlMeta['plan'] = $effective;
            $workPlan = $effective;
        } elseif ($workPlan !== []) {
            $nlMeta['plan'] = $workPlan;
        }
        // A permission refusal ends the request: say so plainly instead of "open steps".
        $nlContent = $finalText !== '' ? $finalText : (
            $permissionRefused
                ? $this->translator->translate('agent.turn.notAllowed')
                : (
                    AgentPromptBuilder::hasBlockingRemainingWork($historyMessages, $workPlan, $requestForGate)
                        ? $this->translator->translate('agent.turn.stepsStillOpen')
                        : $this->translator->translate('agent.turn.emptyModelReply')
                )
        );
        // Keep open Progress steps when the model answers in text: auto-completing them
        // made multi-element requests look finished after the first apply.
        // The window shows the plan of the last live event; make that the plan saved with the answer, so Progress
        // is the same while the turn runs, when it ends and after a reload.
        if (is_array($nlMeta['plan'] ?? null) && $nlMeta['plan'] !== []) {
            $state->emit('plan', ['steps' => $nlMeta['plan']]);
        }
        $state->addMessage([
            'role' => 'assistant',
            'content' => $nlContent,
            'meta' => $nlMeta,
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
        // Prefer the server checklist from the editor request when it applies; otherwise the model plan.
        $requestForChecklist = trim(self::requestQuery($userMessage, $historyMessages));
        if ($requestForChecklist === '' || str_starts_with(trim($userMessage), '[The editor ')) {
            $requestForChecklist = AgentPromptBuilder::latestUserRequestText($historyMessages);
        }
        $modelPlan = self::planCarriedIntoTurn($userMessage, $historyMessages);
        $continuation = is_array($body['continuation'] ?? null) ? $body['continuation'] : [];
        if ($modelPlan !== [] && ($continuation['outcome'] ?? '') === 'applied' && AgentPlan::hasOpenSteps($modelPlan)) {
            $modelPlan = AgentPlan::advance($modelPlan);
        }
        $checklist = AgentRequestChecklist::reconcile($historyMessages, $requestForChecklist);
        if ($checklist !== []) {
            $plan = AgentRequestChecklist::toPlan($checklist);
            $state->setAnchoredPlan($plan);
        } elseif ($modelPlan !== []) {
            $plan = $modelPlan;
            if (($continuation['outcome'] ?? '') === 'applied') {
                $state->setPlan($plan);
            } else {
                $state->plan = $plan;
            }
        } else {
            $plan = [];
            $state->plan = $plan;
        }
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
                $this->premiumCatalog,
                $this->entitlementExplanation,
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
                extra: [
                    'brandContextScope' => 'agent',
                    'messages' => $messages,
                    'turn_id' => $correlationId,
                ],
            ),
            $toolbox,
            $this->agentSettings->isProviderThinkingVisible(),
        );

        $events = new EventDispatcher();
        $events->addListener(self::dispatchedEventName(ToolCallsExecuted::class), static function (ToolCallsExecuted $event) use ($state): void {
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
            $agentResult = $agent->call($this->buildMessages($userMessage, $historyMessages, $context, $plan))->getResult();
            $content = $agentResult->getContent();
            $finalText = is_string($content) ? trim($content) : '';
            // Models sometimes parrot draft-history notes as the final answer; never show that, retry once.
            if (
                AgentPromptBuilder::isCardHistoryEcho($finalText)
                && !$state->isPaused()
                && !$state->failed
                && !$state->isCancelled()
            ) {
                $finalText = '';
                $nudge = $userMessage . "\n\n[System: Do not quote or repeat \"[Prepared change:…]\" or \"Review the proposed changes…\" lines — those are internal history notes, not answers. Call a write tool for my request now, or reply in one short plain sentence about the work.]";
                $retryPlan = $state->plan !== [] ? $state->plan : $plan;
                $agentResult = $agent->call($this->buildMessages($nudge, $historyMessages, $context, $retryPlan))->getResult();
                $content = $agentResult->getContent();
                $finalText = is_string($content) ? trim($content) : '';
                if (AgentPromptBuilder::isCardHistoryEcho($finalText)) {
                    $finalText = '';
                }
            }
            $retryPlan = $state->plan !== [] ? $state->plan : $plan;
            if (
                $this->shouldRetryForRemainingWork($state, $retryPlan, $historyMessages, $userMessage, $finalText)
            ) {
                $reminder = AgentPromptBuilder::remainingWorkReminder($historyMessages, $retryPlan, $userMessage);
                $nudge = $userMessage . "\n\n[System: Open work remains — do not say the request is finished. "
                    . $reminder
                    . ' Call the next write tool now (generate/attach image or the next content step).]';
                $agentResult = $agent->call($this->buildMessages($nudge, $historyMessages, $context, $retryPlan))->getResult();
                $content = $agentResult->getContent();
                $finalText = is_string($content) ? trim($content) : '';
                if (AgentPromptBuilder::isCardHistoryEcho($finalText)) {
                    $finalText = '';
                }
            }
        } catch (MaxIterationsExceededException) {
            $state->addMessage([
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.loopLimit'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId, 'trace' => self::traceWithoutResponses($state->trace), 'orchestratorPause' => true],
            ]);
            $state->pause('loop_limit');
        } catch (\Throwable $exception) {
            if (self::causedByContentRemoved($exception)) {
                // Redacted idempotent replay: not an orchestration failure. Nothing was charged or saved;
                // the editor simply resends (the next turn uses a fresh request_uuid).
                $state->addMessage([
                    'role' => 'assistant',
                    'content' => $this->translator->translate('agent.credits.contentRemoved'),
                    'meta' => ['type' => 'info', 'correlationId' => $correlationId, 'contentRemoved' => true],
                ]);
            } else {
                $state->addMessage([
                    'role' => 'assistant',
                    'content' => $this->creditsErrorMessage($exception),
                    'meta' => ['type' => 'error', 'correlationId' => $correlationId, 'degraded' => true],
                ]);
                $state->failed = true;
            }
        }

        return [$state, $finalText];
    }

    /** The agent runtime may wrap platform exceptions, so look through the previous chain. */
    private static function causedByContentRemoved(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CreditsContentRemovedException) {
                return true;
            }
        }

        return false;
    }

    private function creditsErrorMessage(\Throwable $exception): string
    {
        if ($exception instanceof InsufficientCreditsException) {
            return $exception->getMessage() !== '' && $exception->getMessage() !== 'insufficient_credits'
                ? $exception->getMessage()
                : $this->translator->translate('agent.turn.orchestratorFailed', [$exception->getMessage()]);
        }
        if ($exception instanceof CreditsApiException) {
            $key = match ($exception->errorCode) {
                CreditsApiErrorCodes::MODEL_UNKNOWN => 'agent.credits.modelUnknown',
                CreditsApiErrorCodes::MODEL_NOT_ALLOWED => 'agent.credits.modelNotAllowed',
                CreditsApiErrorCodes::TOOLS_UNSUPPORTED => 'agent.credits.toolsUnsupported',
                CreditsApiErrorCodes::CONTEXT_LENGTH_EXCEEDED => 'agent.credits.contextLength',
                default => null,
            };
            if ($key !== null) {
                return $this->translator->translate($key);
            }
        }

        if (self::looksLikeContextOverflow($exception->getMessage())) {
            return $this->translator->translate('agent.credits.contextLength');
        }

        return $this->translator->translate('agent.turn.orchestratorFailed', [self::readableProviderError($exception->getMessage())]);
    }

    /** Provider wording for "the request is larger than the model's context window". */
    private static function looksLikeContextOverflow(string $raw): bool
    {
        return preg_match('/context[_ ]length|maximum context|context window|too many tokens|prompt is too long|reduce the length/i', $raw) === 1;
    }

    /**
     * Drops change cards that repeat a declined card, or a create that is still waiting to be
     * executed. A follow-up ("check now") otherwise adds a second identical page draft, and
     * Execute all writes both.
     *
     * @param list<array{role: string, content: string, meta: array<string, mixed>}> $messages messages added by this turn
     * @param list<array<string, mixed>> $history the conversation before this turn
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    public static function withoutRepeatedDeclinedDrafts(array $messages, array $history): array
    {
        $blocked = [];
        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }
            $draft = $message['meta']['draft'] ?? null;
            if (($message['meta']['type'] ?? '') !== 'inline_draft' || !is_array($draft)) {
                continue;
            }
            $discarded = ($draft['discarded'] ?? false) === true;
            $applied = ($draft['applied'] ?? false) === true || ($message['meta']['applied'] ?? false) === true;
            $pendingCreate = !$discarded && !$applied && (string) ($draft['action'] ?? '') === 'create';
            if ($discarded || $pendingCreate) {
                $blocked[self::draftSignature($draft)] = true;
            }
        }

        $kept = [];
        foreach ($messages as $message) {
            $draft = $message['meta']['draft'] ?? null;
            if (($message['meta']['type'] ?? '') === 'inline_draft' && is_array($draft)) {
                $signature = self::draftSignature($draft);
                if (isset($blocked[$signature])) {
                    continue;
                }
                // Two identical creates in the same reply count as one card.
                if ((string) ($draft['action'] ?? '') === 'create') {
                    $blocked[$signature] = true;
                }
            }
            $kept[] = $message;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private static function draftSignature(array $draft): string
    {
        $fields = [];
        foreach (is_array($draft['fields'] ?? null) ? $draft['fields'] : [] as $field) {
            if (is_array($field)) {
                $fields[] = [$field['table'] ?? '', $field['uid'] ?? 0, $field['field'] ?? '', $field['proposed'] ?? ''];
            }
        }

        return md5((string) json_encode([
            $draft['tool'] ?? '',
            $draft['kind'] ?? '',
            $fields,
            $draft['arguments'] ?? [],
        ]));
    }

    /**
     * True when a tool of this turn failed because the editor lacks the permission, so the reply
     * has to say "not allowed" and the request must not stay open.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function turnWasRefusedByPermissions(array $messages, string $permissionDeniedText = ''): bool
    {
        foreach ($messages as $message) {
            if (($message['meta']['type'] ?? '') !== 'error') {
                continue;
            }
            $content = (string) ($message['content'] ?? '');
            if ($permissionDeniedText !== '' && str_contains($content, $permissionDeniedText)) {
                return true;
            }
            if (preg_match('/(?:don\'t|do not) have (?:access|permission)|insufficient permissions|not allowed/i', $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turns a provider failure into one short readable sentence: never a raw JSON body or a long
     * dump. The full text stays in the logs.
     *
     * @internal
     */
    public static function readableProviderError(string $raw): string
    {
        $raw = trim($raw);
        $start = strpos($raw, '{');
        if ($start !== false) {
            $end = strrpos($raw, '}');
            $decoded = $end !== false && $end > $start ? json_decode(substr($raw, $start, $end - $start + 1), true) : null;
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? null) : null;
            $raw = is_string($message) && trim($message) !== '' ? trim($message) : trim(substr($raw, 0, $start));
        }
        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw), ' :-');
        if ($raw === '') {
            return 'The AI provider returned an error. Please try again.';
        }

        return mb_strlen($raw) > 200 ? rtrim(mb_substr($raw, 0, 200)) . '…' : $raw;
    }

    /**
     * The name the dispatcher will actually see for a Symfony AI event.
     *
     * Symfony's EventDispatcher keys listeners by `$event::class`. In classic
     * (phar) mode the Agent is php-scoper-prefixed, so it dispatches
     * `NITSAN\T3af\Vendor\…\ToolCallsExecuted`, while `ToolCallsExecuted::class`
     * written here is a compile-time literal of the public name. The phar's
     * class_alias() gives those two the same class identity but NOT the same
     * name, so a listener registered under the public name is never called.
     *
     * The failure is silent — no error, just a dead listener — and here it
     * would mean a paused turn keeps calling tools instead of stopping.
     *
     * ClassResolver ships inside the phar. In Composer mode it is absent and
     * nothing is scoped, so the public name is already correct.
     *
     * @param class-string $publicName
     */
    private static function dispatchedEventName(string $publicName): string
    {
        return class_exists(\NITSAN\T3af\Runtime\ClassResolver::class)
            ? \NITSAN\T3af\Runtime\ClassResolver::resolve($publicName)
            : $publicName;
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @param array<string, mixed> $context
     * @param list<array{title: string, status: string}> $plan
     */
    private function buildMessages(string $userMessage, array $historyMessages, array $context, array $plan = []): MessageBag
    {
        $bag = new MessageBag();
        $system = $this->promptBuilder->buildSystemPrompt($context);
        $appliedBlock = AgentPromptBuilder::appliedRecordsBlock($historyMessages);
        if ($appliedBlock !== '') {
            $system .= "\n" . $appliedBlock;
        }
        $planBlock = AgentPlan::promptBlock($plan);
        $checklist = AgentRequestChecklist::reconcile(
            $historyMessages,
            AgentPromptBuilder::latestUserRequestText($historyMessages) ?: $userMessage,
        );
        if ($checklist !== [] && AgentRequestChecklist::hasOpen($checklist)) {
            $system .= "\n" . AgentRequestChecklist::promptBlock($checklist);
        } elseif ($planBlock !== '') {
            $system .= "\n" . $planBlock;
        }
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
        $notAskedFor = array_flip(self::toolsNotAskedFor($query));
        if ($createContent || self::isPlainEditRequest($query)) {
            // "Change the header of element 5" is an update: with the delete tool at hand the model sometimes
            // answers with a delete card plus a create card instead.
            $notAskedFor['content_delete'] = true;
        }
        $offeredTools = array_values(array_filter(
            $offeredTools,
            static fn(array $tool): bool => !isset($notAskedFor[(string) ($tool['name'] ?? '')]),
        ));

        $offeredNames = array_flip($this->toolNames($offeredTools));
        $candidates = array_values(array_filter(
            $executableTools,
            static fn(array $tool): bool => !isset($offeredNames[(string) ($tool['name'] ?? '')])
                && !isset($notAskedFor[(string) ($tool['name'] ?? '')]),
        ));
        try {
            $found = $this->toolSearch->search($query, $candidates, self::REQUEST_TOOLS)['tools'];
        } catch (\Throwable) {
            $found = [];
        }

        if ($createContent) {
            $found = [...self::createContentTools($executableTools, $offeredNames), ...$found];
        }
        if (self::isPageMoveRequest($query)) {
            $found = [...self::pageMoveTools($executableTools, $offeredNames), ...$found];
        }
        $found = [...self::fileActionTools($query, $executableTools, $offeredNames), ...$found];
        $workPlan = self::planCarriedIntoTurn($userMessage, $historyMessages);
        if (AgentPromptBuilder::hasBlockingRemainingWork($historyMessages, $workPlan, $query)) {
            $found = [...self::imageWorkTools($executableTools, $offeredNames), ...$found];
        } elseif (AgentPromptBuilder::pendingImageAttachNote($historyMessages) !== '') {
            $found = [...self::pendingAttachTools($executableTools, $offeredNames), ...$found];
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
     * When a generated file still needs attaching, keep file_reference_add in the toolbox
     * even if the module tool cap left it out (otherwise the model only retries write_table).
     *
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function pendingAttachTools(array $executableTools, array $alreadyOffered = []): array
    {
        $picked = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === 'file_reference_add' && !isset($alreadyOffered[$name])) {
                $picked[$name] = $tool;
                break;
            }
        }

        return array_values($picked);
    }

    /**
     * When image generate/attach is still open, keep those tools in the toolbox.
     *
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function imageWorkTools(array $executableTools, array $alreadyOffered = []): array
    {
        $picked = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '' || isset($alreadyOffered[$name]) || isset($picked[$name])) {
                continue;
            }
            if ($name === 't3ai_generate_image' || $name === 'file_reference_add') {
                $picked[$name] = $tool;
            }
        }

        return array_values($picked);
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @param list<array{title: string, status: string}> $plan
     */
    private function shouldRetryForRemainingWork(
        AgentTurnState $state,
        array $plan,
        array $historyMessages,
        string $userMessage,
        string $finalText,
    ): bool {
        if ($finalText === '' || $state->isPaused() || $state->failed || $state->isCancelled()) {
            return false;
        }
        if ($state->executedTools !== []) {
            return false;
        }

        return AgentPromptBuilder::hasBlockingRemainingWork($historyMessages, $plan, $userMessage);
    }

    /**
     * Tools that fit only when the editor says so, and the words that ask for them.
     * "Create a new subpage" must not end in a copy of another page.
     *
     * @var array<string, string>
     */
    private const ONLY_WHEN_ASKED = [
        'pages_copy' => '/\b(copy|copies|duplicate|duplicat\w*|clone|kopier\w*|kopie|dupliz\w*|klon\w*)\b/iu',
        // Persists the editor's backend workspace: only when the editor asks to change it.
        'workspace_switch' => '/\b(workspaces?|arbeitsbereich\w*|switch\w*|wechsel\w*)\b/iu',
    ];

    /**
     * @return list<string>
     */
    public static function toolsNotAskedFor(string $query): array
    {
        $names = [];
        foreach (self::ONLY_WHEN_ASKED as $name => $pattern) {
            if (preg_match($pattern, $query) !== 1) {
                $names[] = $name;
            }
        }

        return $names;
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

    public static function isPageMoveRequest(string $query): bool
    {
        $q = mb_strtolower(trim($query));
        if ($q === '' || preg_match('/\b(move|verschieb\w*)\b/u', $q) !== 1) {
            return false;
        }

        return preg_match('/\b(page|pages|seite|seiten)\b/u', $q) === 1;
    }

    /**
     * Keep pages_move in the toolbox when the editor asks to move a page, even if the module cap left it out.
     *
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function pageMoveTools(array $executableTools, array $alreadyOffered = []): array
    {
        foreach ($executableTools as $tool) {
            if ((string) ($tool['name'] ?? '') === 'pages_move' && !isset($alreadyOffered['pages_move'])) {
                return [$tool];
            }
        }

        return [];
    }

    /**
     * A request that changes something that exists and does not ask to delete, remove or replace anything.
     */
    public static function isPlainEditRequest(string $query): bool
    {
        $q = mb_strtolower(trim($query));
        if ($q === '' || preg_match('/\b(delete|remove|replace|clear|erase|trash|l[öo]sch\w*|entfern\w*|ersetz\w*)/u', $q) === 1) {
            return false;
        }

        return preg_match('/\b(change|update|edit|rename|set|[äa]ndere\w*|aktualisier\w*|umbenenn\w*)\b/u', $q) === 1;
    }

    /**
     * File and folder tools for a plain file request ("copy the file ...", "Ordner anlegen"). The tool
     * search caps the extra tools per request and can rank an unrelated tool above file_copy.
     *
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function fileActionTools(string $query, array $executableTools, array $alreadyOffered = []): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return [];
        }
        $file = preg_match('/\b(file|files|datei|dateien)\b/u', $q) === 1;
        $folder = preg_match('/\b(folder|folders|directory|directories|ordner|verzeichnis\w*)\b/u', $q) === 1;
        if (!$file && !$folder) {
            return [];
        }
        $wanted = [];
        $verbs = [
            'copy' => '/\b(copy|duplicate|kopier\w*|dupliz\w*)/u',
            'move' => '/\b(move|verschieb\w*)/u',
            'rename' => '/\b(rename|umbenenn\w*)/u',
        ];
        foreach ($verbs as $verb => $pattern) {
            if (preg_match($pattern, $q) !== 1) {
                continue;
            }
            if ($file) {
                $wanted[] = 'file_' . $verb;
            }
            if ($folder && $verb !== 'copy') {
                $wanted[] = 'directory_' . $verb;
            }
        }
        if ($folder && preg_match('/\b(create|new|add|anlegen|erstell\w*|neu\w*)\b/u', $q) === 1) {
            $wanted[] = 'directory_create';
        }
        $picked = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if (in_array($name, $wanted, true) && !isset($alreadyOffered[$name])) {
                $picked[] = $tool;
            }
        }

        return $picked;
    }

    /**
     * The request the "is anything still open?" gate must judge: the current message (plus the
     * earlier turn for short replies), or the original request when this is a confirm/decline
     * continuation.
     *
     * @param list<array<string, mixed>> $historyMessages
     */
    private static function requestForGate(string $userMessage, array $historyMessages): string
    {
        $request = trim(self::requestQuery($userMessage, $historyMessages));
        if ($request === '' || str_starts_with(trim($userMessage), '[The editor ')) {
            $request = AgentPromptBuilder::latestUserRequestText($historyMessages);
        }

        return $request !== '' ? $request : $userMessage;
    }

    /**
     * Progress plan of an earlier request only carries over into a continuation or a short reply
     * ("yes", "go on"). A new, self-contained request starts without the old plan — otherwise an
     * open step left by one request answered every later message with "There are still open steps".
     *
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array{title: string, status: string}>
     */
    private static function planCarriedIntoTurn(string $userMessage, array $historyMessages): array
    {
        $message = trim($userMessage);
        $isContinuation = str_starts_with($message, '[The editor ');
        if (!$isContinuation && mb_strlen($message) >= self::SHORT_REPLY_CHARS) {
            return [];
        }

        return AgentPlan::latest($historyMessages);
    }

    /**
     * Whether a short message names its own action or question instead of answering the previous turn
     * ("yes", "do it", "the second one").
     */
    private static function isStandaloneRequest(string $message): bool
    {
        // "Create it" / "delete that one" only make sense with the turn before them.
        if (preg_match_all('/\S+/u', $message) < 3 || preg_match('/\b(?:it|that|this|them|those|these|one|es|das|dies\w*|diese\w*)\b/iu', $message) === 1) {
            return false;
        }

        return preg_match(
            '/^\s*(?:please\s+|bitte\s+)?(?:list|show|display|get|find|search|count|open|create|add|make|write|delete|remove|rename|change|update|set|move|copy|translate|publish|switch|explain|describe|summari[sz]e|which|what|who|where|when|how|why|zeig\w*|liste?\w*|such\w*|finde\w*|erstell\w*|f(?:ü|ue)ge?\w*|l(?:ö|oe)sch\w*|benenn\w*|(?:ä|ae)nder\w*|verschieb\w*|kopier\w*|(?:ü|ue)bersetz\w*|welche\w*|was|wer|wo|wie)\b/iu',
            $message,
        ) === 1;
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

        // A short message that is a complete request of its own ("List all workspaces") is not a reply to the
        // previous turn: gluing the earlier question and answer to it would turn words from them (content
        // element names, for example) into steps of a request nobody made.
        if (!$isContinuation && self::isStandaloneRequest($message)) {
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
            // After a confirm / decline only the editor's own request counts; the assistant's wording of the last
            // answer must not add steps (an edit must not start "creating" the elements the answer mentioned).
            $isReply = !$isContinuation
                && ($entry['role'] ?? '') === 'assistant'
                && ($meta['type'] ?? '') === 'nl_reply'
                && $earlier === [];
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
