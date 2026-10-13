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
use NITSAN\NsT3AF\Mcp\Tool\Agent\AskClarificationTool;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Toolbox\Event\ToolCallsExecuted;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

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
        private AgentProviderTurnErrorPresenter $providerTurnErrorPresenter,
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
            // Menu already hides news tools; free-text still tried record_search on the news table.
            if (!ExtensionManagementUtility::isLoaded('news') && self::isNewsRelatedRequest($userMessage)) {
                return $this->single($emitEvent, [
                    'role' => 'assistant',
                    'content' => 'News isn\'t available on this site (EXT:news is not installed).',
                    'meta' => ['type' => 'info', 'correlationId' => $correlationId, 'orchestratorPause' => true],
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
        $rawFinalText = $finalText;
        $finalText = AgentPromptBuilder::stripLeakedToolCall($finalText);
        $finalText = AgentPromptBuilder::collapseRepeatedReply($finalText);
        $finalText = AgentPromptBuilder::stripGluedToolResultPrefix($finalText);

        // Model wrote ask_clarification as JSON text instead of calling the tool — show buttons.
        $embeddedClarification = AskClarificationTool::parseEmbeddedClarification($finalText);
        if (
            $embeddedClarification !== null
            && !$state->isPaused()
            && !$state->failed
            && !$state->isCancelled()
        ) {
            $options = AskClarificationTool::normalizeOptions($embeddedClarification['options']);
            $state->addMessage([
                'role' => 'assistant',
                'content' => $embeddedClarification['question'],
                'meta' => [
                    'type' => 'clarification',
                    'tool' => 'ask_clarification',
                    'options' => $options,
                    'correlationId' => $correlationId,
                    'orchestratorPause' => true,
                    'llmSummary' => 'Asked the editor: ' . $embeddedClarification['question']
                        . ($options !== [] ? ' Options: ' . implode(' | ', $options) : ''),
                ],
            ]);
            $state->pause('clarification');
            $finalText = '';
        }

        // Drop a card the editor already declined, and a second copy of a create that is still waiting.
        $declinedDropped = 0;
        $failedDropped = 0;
        $state->messages = self::withoutRepeatedDeclinedDrafts($state->messages, $historyMessages, ($continuation['outcome'] ?? '') === 'declined', $declinedDropped, $failedDropped);
        $cardDropped = $declinedDropped > 0;

        $state->attachPendingPlan();

        if ($state->isCancelled()) {
            $state->addMessage([
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.governanceBlocked', [$state->cancelledReason() !== '' ? $state->cancelledReason() : '-']),
                'meta' => ['type' => 'governance_blocked', 'correlationId' => $correlationId],
            ]);

            return ['messages' => $state->messages, 'paused' => false, 'pauseReason' => null];
        }

        $cardLeft = in_array('inline_draft', array_map(static fn(array $message): string => (string) ($message['meta']['type'] ?? ''), $state->messages), true);
        // A review pause whose only card was dropped above would leave the window waiting for nothing.
        if ($state->isPaused() && ($cardLeft || $state->pauseReason() !== 'draft_review')) {
            return ['messages' => $state->messages, 'paused' => true, 'pauseReason' => $state->pauseReason()];
        }

        if (
            (($continuation['outcome'] ?? '') === 'declined' || $cardDropped)
            && !$state->failed
            && !$cardLeft
            && (
                trim($finalText) === ''
                || AgentPromptBuilder::isCardHistoryEcho($finalText)
                || self::declinedReplyOffersItAgain(($continuation['outcome'] ?? '') === 'declined', $state->executedTools, $historyMessages)
            )
        ) {
            $finalText = $this->translator->translate(
                ($continuation['outcome'] ?? '') === 'declined' ? 'agent.turn.declinedAskInstead' : 'agent.turn.declinedNotRepeated',
            );
        }

        if ($failedDropped > 0 && !$state->failed && !$cardLeft && (trim($finalText) === '' || AgentPromptBuilder::isCardHistoryEcho($finalText))) {
            $finalText = $this->translator->translate('agent.turn.failedNotRepeated');
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

        $missingPageUid = AgentPromptBuilder::leakedMissingPageUid($rawFinalText);
        if ($missingPageUid > 0) {
            $finalText = $this->translator->translate('agent.page.missingUid', [$missingPageUid]);
        }

        if ($finalText !== '') {
            // A cap ("4 of the 6") or a fallback generator is named in the reply, not only in the collapsed step.
            $imageNotice = AgentPromptBuilder::imageNoticeOwed([...$historyMessages, ...$state->messages], $finalText);
            if ($imageNotice !== '') {
                $finalText = rtrim($finalText) . "\n\n" . $imageNotice;
            }
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
        if ($requestForChecklist === '' || str_starts_with(trim($userMessage), '[The editor ') || AgentPromptBuilder::isGoOnReply($userMessage)) {
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
        $body['requestQuery'] = self::requestQuery($userMessage, $historyMessages);
        $body['requestForGate'] = $requestForChecklist;
        // Prior turns (news readback, generated fileUid) must reach write-tool enrichment.
        $body['conversationHistory'] = $historyMessages;

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
            $agentResult = $agent->call($this->buildMessages($userMessage, $historyMessages, $context, $plan, $requestForChecklist))->getResult();
            $content = $agentResult->getContent();
            $finalText = is_string($content) ? trim($content) : '';
            $retried = false;
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
                $agentResult = $agent->call($this->buildMessages($nudge, $historyMessages, $context, $retryPlan, $requestForChecklist))->getResult();
                $content = $agentResult->getContent();
                $finalText = is_string($content) ? trim($content) : '';
                if (AgentPromptBuilder::isCardHistoryEcho($finalText)) {
                    $finalText = '';
                }
                $retried = true;
            }
            // Invented "[Remove from translation queue] …" with no tool call this turn — retry once.
            if (
                !$retried
                && $state->executedTools === []
                && AgentPromptBuilder::isFabricatedToolResultEcho($finalText)
                && !$state->isPaused()
                && !$state->failed
                && !$state->isCancelled()
            ) {
                $nudge = $userMessage . "\n\n[System: You wrote a fake tool result in brackets without calling a tool. Never invent \"[…]\" result lines. Call the matching write/queue tool now so the editor gets an approval card, or say in one plain sentence that there is nothing to do.]";
                $retryPlan = $state->plan !== [] ? $state->plan : $plan;
                $agentResult = $agent->call($this->buildMessages($nudge, $historyMessages, $context, $retryPlan, $requestForChecklist))->getResult();
                $content = $agentResult->getContent();
                $finalText = is_string($content) ? trim($content) : '';
                // Still a fake bracketed result after the nudge: drop it (do not re-check executedTools —
                // PHPStan cannot see that the agent call may have filled it via the toolbox).
                if (
                    AgentPromptBuilder::isCardHistoryEcho($finalText)
                    || AgentPromptBuilder::isFabricatedToolResultEcho($finalText)
                ) {
                    $finalText = '';
                }
                $retried = true;
            }
            $retryPlan = $state->plan !== [] ? $state->plan : $plan;
            if (
                $this->shouldRetryForRemainingWork($state, $retryPlan, $historyMessages, $requestForChecklist ?: $userMessage, $finalText)
            ) {
                $reminder = AgentPromptBuilder::remainingWorkReminder($historyMessages, $retryPlan, $requestForChecklist ?: $userMessage);
                $nudge = $userMessage . "\n\n[System: Open work remains — do not say the request is finished. "
                    . $reminder
                    . ' Call the next write tool now (generate/attach image or the next content step).]';
                $agentResult = $agent->call($this->buildMessages($nudge, $historyMessages, $context, $retryPlan, $requestForChecklist))->getResult();
                $content = $agentResult->getContent();
                $finalText = is_string($content) ? trim($content) : '';
                if (AgentPromptBuilder::isCardHistoryEcho($finalText)) {
                    $finalText = '';
                }
                $retried = true;
            }
            // Some models write the tool call as text ("to=functions.t3ai_translate_news {…}") plus garbage
            // instead of calling the tool. That is never an answer: ask once for a real call, else show nothing.
            if (
                !$retried
                && self::looksLikeLeakedToolCall($finalText)
                && !$state->isPaused()
                && !$state->failed
                && !$state->isCancelled()
            ) {
                $nudge = $userMessage . "\n\n[System: Your last reply contained a tool call written as text. Never write tool calls in the reply: call the tool itself now, or answer in one short plain sentence.]";
                $retryPlan = $state->plan !== [] ? $state->plan : $plan;
                $agentResult = $agent->call($this->buildMessages($nudge, $historyMessages, $context, $retryPlan, $requestForChecklist))->getResult();
                $content = $agentResult->getContent();
                $retryText = is_string($content) ? trim($content) : '';
                $finalText = AgentPromptBuilder::isCardHistoryEcho($retryText) ? '' : $retryText;
                $retried = true;
            }
            if (self::looksLikeLeakedToolCall($finalText)) {
                $finalText = '';
            }
            // The model pasted the new text into chat and offered to apply it, but sent no draft: nothing can
            // be applied from chat text. Ask once for the write tool; a normal answer is never forced.
            if (
                !$retried
                && self::offersUnbackedApply($finalText)
                && !$state->isPaused()
                && !$state->failed
                && !$state->isCancelled()
            ) {
                $nudge = $userMessage . "\n\n[System: You wrote the new text in chat and offered to apply it, but no change was prepared, so nothing can be applied. Call the write tool now with the new text so the editor gets an approval card. Do not paste the text in chat again.]";
                $retryPlan = $state->plan !== [] ? $state->plan : $plan;
                $agentResult = $agent->call($this->buildMessages($nudge, $historyMessages, $context, $retryPlan, $requestForChecklist))->getResult();
                $content = $agentResult->getContent();
                $retryText = is_string($content) ? trim($content) : '';
                $finalText = AgentPromptBuilder::isCardHistoryEcho($retryText) ? '' : $retryText;
            }
            $finalText = self::withoutLeadingHistoryTag($finalText);
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
                    'content' => $this->creditsErrorMessage($exception, $user),
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

    private function creditsErrorMessage(\Throwable $exception, BackendUserAuthentication $user): string
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

        if (self::looksLikeTimeout($exception)) {
            return $this->translator->translate('agent.turn.timeout');
        }

        if (AgentProviderTurnErrorPresenter::looksLikeContextOverflow($exception)) {
            return $this->translator->translate('agent.credits.contextLength');
        }

        return $this->providerTurnErrorPresenter->present($exception, $this->translator, (bool) $user->isAdmin());
    }

    /**
     * History notes read "[Read the translation queue] …"; a model that copies one starts its answer with
     * that tag. The tag is internal, so it is dropped when real text follows it.
     */
    public static function withoutLeadingHistoryTag(string $text): string
    {
        $stripped = preg_replace('/^\s*\[(?:Read|Prepared|Applied|Searched|Listed|Checked|Ran)[^\]\n]{0,80}\]\s*/u', '', $text);

        return is_string($stripped) && trim($stripped) !== '' ? $stripped : $text;
    }

    /** A tool call (or its channel markers) written into the reply text instead of being called. */
    public static function looksLikeLeakedToolCall(string $text): bool
    {
        return preg_match('/\bto=functions\.[a-z0-9_]+|<\|(?:channel|message|constrain|call|start)\|>|\bfunctions\.[a-z0-9_]+\s*\{/iu', $text) === 1;
    }

    /**
     * Names a page to create (a page, subpage, blog post or page tree) and not just "this page" as the place for
     * a text: "Add an intro paragraph to this page" and "Introduce our services" are content, not new pages.
     */
    public static function asksForNewPage(string $query): bool
    {
        $stripped = preg_replace(
            '/\b(?:to|on|for|of|in|at|from|about|auf|zu|f[üu]r|in|von)\s+(?:this|the\s+(?:current|open)|my|our|diese[rsmn]?|die\s+aktuelle)\s+(?:page|seite)\b/iu',
            ' ',
            $query,
        );
        $stripped = is_string($stripped) ? $stripped : $query;

        return preg_match('/\b(pages?|subpages?|sub-pages?|seiten?|unterseiten?|blog\s*posts?|blogbeitr\w*|blogartikel|page\s*tree|seitenbaum|seitenstruktur|site\s*tree|structure|struktur|sitemap)\b/iu', $stripped) === 1;
    }

    /**
     * Asks to write or draft a new news article (not to change, list or delete one).
     */
    public static function isNewsCreateRequest(string $query): bool
    {
        $s = mb_strtolower($query);

        return preg_match('/\b(news|newsartikel|nachricht\w*|meldung\w*)\b/u', $s) === 1
            // "generate" is how editors phrase this at least as often as "create"; without it the
            // turn misses newsCreateTools() and drifts to whatever the module start set offers (SEO).
            && preg_match('/\b(write|create|generate|draft|compose|add|make|new|schreib\w*|verfass\w*|erstell\w*|generier\w*|erzeug\w*|anleg\w*|neue[rsn]?)\b/u', $s) === 1
            && preg_match('/\b(delete|remove|translate|rename|list|show|l[öo]sch\w*|entfern\w*|[üu]bersetz\w*|umbenenn\w*|zeig\w*)\b/u', $s) !== 1;
    }

    /**
     * Any ask that needs EXT:news (create, translate, find, delete a news article).
     */
    public static function isNewsRelatedRequest(string $query): bool
    {
        $s = mb_strtolower(trim($query));
        if ($s === '') {
            return false;
        }
        if (preg_match('/\b(news\s*articles?|news\s*records?|tx_news|newsartikel|nachricht\w*|meldung\w*)\b/u', $s) === 1) {
            return true;
        }

        return preg_match('/\bnews\b/u', $s) === 1
            && preg_match('/\b(translate|[üu]bersetz\w*|create|erstell\w*|write|schreib\w*|generate|generier\w*|delete|l[öo]sch\w*|find|search|list|zeig\w*)\b/u', $s) === 1;
    }

    /**
     * A news request must not end in a tt_content or pages record, unless the editor also named one
     * ("a news article and a content element").
     */
    public static function isNewsRequestAnsweredWithOtherTable(string $tableName, string $query): bool
    {
        if (!in_array(strtolower(trim($tableName)), ['tt_content', 'pages'], true)) {
            return false;
        }

        return preg_match('/\b(content\s*elements?|inhaltselement\w*|text\s*elements?|paragraphs?|absatz|absätze|pages?|seiten?|headings?|[üu]berschrift\w*)\b/iu', $query) !== 1;
    }

    /**
     * True when a reply offers to apply a change ("Would you like to apply this change?") — which is only
     * honest when a draft card came with it.
     */
    public static function offersUnbackedApply(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        return preg_match(
            '/\b(?:would\s+you\s+like(?:\s+me)?\s+to|shall\s+i|should\s+i|want\s+me\s+to|do\s+you\s+want(?:\s+me)?\s+to)\s+(?:go\s+ahead\s+and\s+)?(?:apply|save|update|replace|write|use)\b/iu',
            $text,
        ) === 1 || preg_match(
            '/\b(?:soll\s+ich|m[öo]chten\s+sie|m[öo]chtest\s+du|willst\s+du)\b[^.?!]{0,60}\b(?:anwenden|[üu]bernehm\w*|speicher\w*|ersetz\w*|aktualisier\w*)\b/iu',
            $text,
        ) === 1;
    }

    /** The provider did not answer in time (idle timeout, request timeout), however the platform wrapped it. */
    public static function looksLikeTimeout(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface) {
                return true;
            }
            if (preg_match('/idle timeout|timed out|timeout reached|operation timed out/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drops change cards that repeat a declined card, or a create that is still waiting to be
     * executed. A follow-up ("check now") otherwise adds a second identical page draft, and
     * Execute all writes both. A card of a tool the editor declined in this request (other than
     * write_table) is not offered again in it, and right after a decline a create of the same
     * content type is dropped too, even when a field or argument differs. A card whose Apply failed
     * for good (bad URL, unknown host) is not offered again with the same arguments.
     *
     * @param list<array{role: string, content: string, meta: array<string, mixed>}> $messages messages added by this turn
     * @param list<array<string, mixed>> $history the conversation before this turn
     * @param int $declinedDropped set to the number of cards dropped because the editor declined them
     * @param int $failedDropped set to the number of cards dropped because the same Apply already failed for good
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    public static function withoutRepeatedDeclinedDrafts(array $messages, array $history, bool $afterDecline = false, int &$declinedDropped = 0, int &$failedDropped = 0): array
    {
        $declinedDropped = 0;
        $failedDropped = 0;
        $blocked = [];
        $declinedCreates = [];
        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') === 'user' && ($message['meta']['type'] ?? '') !== 'continuation') {
                $declinedCreates = [];
            }
            $draft = $message['meta']['draft'] ?? null;
            if (($message['meta']['type'] ?? '') !== 'inline_draft' || !is_array($draft)) {
                continue;
            }
            $discarded = ($draft['discarded'] ?? false) === true;
            $applied = ($draft['applied'] ?? false) === true || ($message['meta']['applied'] ?? false) === true;
            if (!$discarded && !$applied && ($draft['failed'] ?? false) === true) {
                $blocked[self::draftSignature($draft)] = 'failed';
                continue;
            }
            $pendingCreate = !$discarded && !$applied && (string) ($draft['action'] ?? '') === 'create';
            if ($discarded || $pendingCreate) {
                $blocked[self::draftSignature($draft)] = $discarded ? 'declined' : 'pending';
            }
            if ($discarded && (string) ($draft['action'] ?? '') === 'create') {
                $declinedCreates[self::createdKind($draft)] = true;
            }
            // A declined tool card ("Generate an image") comes back with other wording, never with the same arguments.
            if ($discarded && !in_array((string) ($draft['tool'] ?? ''), ['', 'write_table'], true)) {
                $declinedCreates[self::declinedToolKey((string) $draft['tool'])] = true;
            }
        }

        $kept = [];
        foreach ($messages as $message) {
            $draft = $message['meta']['draft'] ?? null;
            if (($message['meta']['type'] ?? '') === 'inline_draft' && is_array($draft)) {
                $signature = self::draftSignature($draft);
                if (isset($blocked[$signature])) {
                    $declinedDropped += $blocked[$signature] === 'declined' ? 1 : 0;
                    $failedDropped += $blocked[$signature] === 'failed' ? 1 : 0;
                    continue;
                }
                if (
                    ($afterDecline && (string) ($draft['action'] ?? '') === 'create' && isset($declinedCreates[self::createdKind($draft)]))
                    || isset($declinedCreates[self::declinedToolKey((string) ($draft['tool'] ?? ''))])
                ) {
                    ++$declinedDropped;
                    continue;
                }
                // Two identical creates in the same reply count as one card.
                if ((string) ($draft['action'] ?? '') === 'create') {
                    $blocked[$signature] = 'pending';
                }
            }
            $kept[] = $message;
        }

        return $kept;
    }

    /**
     * Key of a declined tool card. The translate tools are one family: after the editor cancels a page
     * translation, the same translation must not come back through another translate tool.
     */
    private static function declinedToolKey(string $tool): string
    {
        $tool = strtolower(trim($tool));

        return in_array($tool, ['t3ai_translate_page', 't3ai_translate_content', 't3ai_translate_news', 't3ai_record_translate'], true)
            ? 'tool translate'
            : 'tool ' . $tool;
    }

    /**
     * After the editor cancelled the only step of a request, a text-only reply is the model asking
     * "Shall I translate into German, French or Hindi?" - the cancelled action offered again. With no
     * tool run, no card and no step of the request left, that reply is replaced by the fixed
     * "nothing was written, what instead?" text.
     *
     * @param array<int|string, mixed> $executedTools
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function declinedReplyOffersItAgain(bool $declined, array $executedTools, array $historyMessages): bool
    {
        // The plan is left out on purpose: its step for the cancelled action would still look open.
        return $declined
            && $executedTools === []
            && !AgentPromptBuilder::hasBlockingRemainingWork($historyMessages, []);
    }

    /**
     * "Translate the first / last content element into French": one element by position, with no
     * "whole page" in the request. The page translation tool is the wrong one for it.
     */
    public static function asksForOneElementTranslation(string $request): bool
    {
        return AgentToolTurnProcessor::requestedElementPosition($request) !== null
            && preg_match('/\b(?:whole|entire|complete|full)\s+page\b|\bgesamte[nr]?\s+seite\b|\bganze[nr]?\s+seite\b/iu', $request) !== 1;
    }

    /**
     * What a create card makes: its tool, table and content type ("write_table tt_content textmedia").
     *
     * @param array<string, mixed> $draft
     */
    private static function createdKind(array $draft): string
    {
        $table = '';
        $type = '';
        foreach (is_array($draft['fields'] ?? null) ? $draft['fields'] : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $table = $table !== '' ? $table : (string) ($field['table'] ?? '');
            if (in_array(strtolower((string) ($field['field'] ?? '')), ['ctype', 'doktype'], true)) {
                $type = strtolower(trim((string) ($field['proposed'] ?? '')));
            }
        }

        return implode(' ', [(string) ($draft['tool'] ?? ''), $table, $type]);
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

        $arguments = $draft['arguments'] ?? [];
        // A tool card keeps its arguments only in the summary ("upload from https://… to user_upload").
        $summary = $fields === [] && $arguments === [] ? (string) ($draft['summary'] ?? '') : '';

        return md5((string) json_encode([
            $draft['tool'] ?? '',
            $draft['kind'] ?? '',
            $fields,
            $arguments,
            $summary,
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
        return AgentProviderTurnErrorPresenter::readableProviderError($raw);
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
    private function buildMessages(string $userMessage, array $historyMessages, array $context, array $plan = [], string $request = ''): MessageBag
    {
        // A new request is not in the history yet: the latest user message there belongs to the previous one.
        $request = trim($request) !== '' ? $request : (AgentPromptBuilder::latestUserRequestText($historyMessages) ?: $userMessage);
        $bag = new MessageBag();
        $system = $this->promptBuilder->buildSystemPrompt($context);
        $appliedBlock = AgentPromptBuilder::appliedRecordsBlock($historyMessages);
        if ($appliedBlock !== '') {
            $system .= "\n" . $appliedBlock;
        }
        $planBlock = AgentPlan::promptBlock($plan);
        $checklist = AgentRequestChecklist::reconcile($historyMessages, $request);
        if ($checklist !== []) {
            // A closed checklist outranks the saved plan: the editor may have declined what that plan still lists.
            $block = AgentRequestChecklist::promptBlock($checklist, $request);
            $system .= $block !== '' ? "\n" . $block : '';
        } elseif ($planBlock !== '') {
            $system .= "\n" . $planBlock;
        }
        $unavailableFiles = AgentRequestedFiles::promptNote($historyMessages, $request);
        if ($unavailableFiles !== '') {
            $system .= "\n" . $unavailableFiles;
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
        $gateRequest = self::requestForGate($userMessage, $historyMessages);
        $appliedCreateDone = $createContent
            && str_starts_with(ltrim($userMessage), '[The editor confirmed')
            && str_contains($userMessage, 'it was applied')
            && !AgentPromptBuilder::asksForMultipleContentPieces($gateRequest)
            && !AgentPromptBuilder::hasBlockingRemainingWork(
                $historyMessages,
                self::planCarriedIntoTurn($userMessage, $historyMessages),
                $gateRequest,
            );
        if ($appliedCreateDone) {
            // Intro/paragraph create already applied: do not invent a follow-up change or delete card.
            $notAskedFor['write_table'] = true;
            $notAskedFor['content_delete'] = true;
            $notAskedFor['t3ai_create_content_element'] = true;
        }
        $newsCreate = self::isNewsCreateRequest($query);
        if (!self::asksForNewPage($query)) {
            foreach (self::PAGE_CREATE_TOOL_NAMES as $pageCreateTool) {
                $notAskedFor[$pageCreateTool] = true;
            }
        }
        if ($newsCreate) {
            // A news article is a news record: not a page element, and no picture card unless a picture was asked for.
            // DualMode create-news tools stay agent-hidden; write_table is the create path — do not stall on capabilities.
            $notAskedFor['t3ai_create_content_element'] = true;
            $notAskedFor['explain_capabilities'] = true;
            if (preg_match('/\b(seo|meta|keywords?|schema|structured\s+data|suchmaschinen\w*|metadaten)\b/iu', $query) !== 1) {
                $notAskedFor['t3ai_apply_schema_markup'] = true;
                $notAskedFor['t3ai_generate_keywords'] = true;
                $notAskedFor['t3ai_generate_meta_description'] = true;
            }
            if (preg_match('/\b(picture|pictures|image|images|photo|photos|illustration|bild\w*|foto\w*|grafik\w*)\b/iu', $query) !== 1) {
                $notAskedFor['t3ai_generate_image'] = true;
            }
        }
        $schemaRequest = self::isSchemaRequest($query);
        if ($schemaRequest && preg_match('/\b(folders?|director(?:y|ies)|ordner|verzeichnis\w*)\b/iu', $query) !== 1) {
            // "Generate a Person schema" is JSON-LD for a page, not a folder or a content element.
            // write_table stays available for combined requests ("… and rename the header");
            // T3afToolbox still refuses a tt_content create on a pure schema ask.
            foreach (['directory_create', 'directory_move', 'directory_rename', 'directory_delete'] as $directoryTool) {
                $notAskedFor[$directoryTool] = true;
            }
            $notAskedFor['t3ai_create_content_element'] = true;
        }
        // One generate per open request: a second card is often applied together and doubles files/credits.
        if (self::imageAlreadyGeneratedThisRequest($userMessage, $historyMessages)) {
            $notAskedFor['t3ai_generate_image'] = true;
        }
        // Open generate card or remaining picture work: never detour into SEO / voice-over.
        if (
            self::hasOpenGenerateImageDraft($historyMessages)
            || AgentPromptBuilder::pendingImageAttachNote($historyMessages) !== ''
            || preg_match('/\b(picture|pictures|image|images|photo|photos|illustration|bild\w*|foto\w*|grafik\w*)\b/iu', $query) === 1
        ) {
            $notAskedFor['t3ai_generate_all_seo'] = true;
            $notAskedFor['t3ai_generate_seo_batch'] = true;
            $notAskedFor['t3aa_generate_voice_over'] = true;
        }
        if ($createContent || self::isPlainEditRequest($query) || self::isNewsOnlyRequest($query) || self::isVisibilityRequest($query)) {
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

        if ($createContent && !$appliedCreateDone) {
            $found = [...self::createContentTools($executableTools, $offeredNames), ...$found];
        }
        if (self::isPageMoveRequest($query)) {
            $found = [...self::pageMoveTools($executableTools, $offeredNames), ...$found];
        }
        if ($newsCreate) {
            $found = [...self::newsCreateTools($executableTools, $offeredNames), ...$found];
        }
        if ($schemaRequest) {
            $found = [...self::schemaTools($executableTools, $offeredNames), ...$found];
        }
        if (self::asksSeoForAllPages($query) || self::isMassSeoEnableRequest($query)) {
            $found = [...self::toolsByName(['pages_tree', 'pages_list', 't3ai_generate_seo_batch', 't3ai_mass_seo_queue_add'], $executableTools, $offeredNames), ...$found];
        }
        $found = [...self::fileActionTools($query, $executableTools, $offeredNames), ...$found];
        $workPlan = self::planCarriedIntoTurn($userMessage, $historyMessages);
        if (AgentPromptBuilder::hasBlockingRemainingWork($historyMessages, $workPlan, $gateRequest)) {
            $found = [...self::imageWorkTools($executableTools, $offeredNames, $historyMessages, $gateRequest), ...$found];
        } elseif (
            !AgentRequestedFiles::asksOnlyForGeneratedImage($gateRequest)
            && AgentPromptBuilder::pendingImageAttachNote($historyMessages) !== ''
        ) {
            $found = [...self::pendingAttachTools($executableTools, $offeredNames), ...$found];
        }

        $byName = [];
        foreach ([...$offeredTools, ...$found] as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name !== '') {
                $byName[$name] = $tool;
            }
        }
        if (
            AgentPromptBuilder::pendingAttachFileUids($historyMessages, $gateRequest) === []
            || AgentPromptBuilder::hasOpenFileReferenceDraft($historyMessages)
        ) {
            unset($byName['file_reference_add']);
        }
        ksort($byName);

        return array_values($byName);
    }

    /**
     * "Delete the Winter news" is about a news record. With the tt_content delete tool at hand a model
     * (Mistral) took the news uid for a content element on another site.
     */
    public static function isNewsOnlyRequest(string $query): bool
    {
        $s = mb_strtolower($query);
        if (preg_match('/\b(news|newsartikel|nachricht\w*|meldung\w*)\b/u', $s) !== 1) {
            return false;
        }

        return preg_match('/\b(content\s+elements?|inhaltselement\w*|text\s*block|textblock|block|element|header|[üu]berschrift)\b/u', $s) !== 1;
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
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array<string, mixed>>
     */
    public static function imageWorkTools(array $executableTools, array $alreadyOffered = [], array $historyMessages = [], string $request = ''): array
    {
        $needsAttach = AgentPromptBuilder::pendingAttachFileUids($historyMessages, $request) !== [];
        $picked = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '' || isset($alreadyOffered[$name]) || isset($picked[$name])) {
                continue;
            }
            if ($name === 't3ai_generate_image') {
                $picked[$name] = $tool;
                continue;
            }
            if ($name === 'file_reference_add' && $needsAttach) {
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

    /** Tools that create a page (tree) with AI text: only for a request that names a page to create. */
    public const PAGE_CREATE_TOOL_NAMES = [
        't3ai_create_page_simple',
        't3ai_create_page_advanced',
        't3ai_create_page_structure',
        't3ai_create_blog_simple',
        't3ai_create_blog_advanced',
    ];

    /**
     * Tools that fit only when the editor says so, and the words that ask for them.
     * "Create a new subpage" must not end in a copy of another page.
     *
     * @var array<string, string>
     */
    private const ONLY_WHEN_ASKED = [
        'pages_copy' => '/\b(copy|copies|duplicate|duplicat\w*|clone|kopier\w*|kopie|dupliz\w*|klon\w*)\b/iu',
        // Persists the editor's backend workspace: only when the editor asks to change it.
        // A move card for "change the subheader" was applied as an edit and then reported as done.
        'content_move' => '/\b(move|moves|moved|moving|reorder\w*|reposition\w*|relocate\w*|verschieb\w*|versetz\w*|umsortier\w*|nach\s+(?:oben|unten)|position)\b/iu',
        'pages_move' => '/\b(move|moves|moved|moving|verschieb\w*)\b/iu',
        'workspace_switch' => '/\b(workspaces?|arbeitsbereich\w*|switch\w*|wechsel\w*)\b/iu',
        // A rewrite or edit is not a summary: the summarize tool answered "rewrite friendlier" with an unrelated welcome text.
        't3aa_summarize_content' => '/\b(summar\w*|tl;?dr|zusammenfass\w*|kurzfassung|abstract|gist)\b/iu',
        // Generate+attach must not detour into a metadata card with a fake /generated-image.jpg path.
        't3aa_update_file_metadata' => '/\b(alt\s*text|image\s*texts?|file\s*meta|metadata|bildtext\w*|alternativtext\w*|datei\s*meta)\b/iu',
        // "Generate the picture" must not become Write all SEO texts / SEO batch.
        't3ai_generate_all_seo' => '/\b(seo|meta\s*desc|meta\s*title|og\s*title|og\s*desc|open\s*graph|schema\s*markup|suchmaschinen\w*|metadaten)\b/iu',
        't3ai_generate_seo_batch' => '/\b(seo|meta\s*desc|meta\s*title|og\s*title|og\s*desc|open\s*graph|schema\s*markup|suchmaschinen\w*|metadaten)\b/iu',
        // A read-only report that spends AI credits: only when the editor asks for an analysis.
        't3ai_analyze_content' => '/\b(analy[sz]\w*|analysis|readab\w*|inhaltsanalyse|auswert\w*|bewert\w*|how good)\b/iu',
        't3aa_generate_voice_over' => '/\b(voice\s*-?\s*over|voiceover|audio\s*narrat\w*|sprachausgabe|vorlesen|vorlese\w*)\b/iu',
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

    /**
     * True when an image generate card is still open, or a generated/uploaded file is waiting to attach.
     * Stops a second generate card that the editor would Apply together with the first.
     *
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function imageAlreadyGeneratedThisRequest(string $userMessage, array $historyMessages): bool
    {
        unset($userMessage);

        return self::hasOpenGenerateImageDraft($historyMessages)
            || AgentPromptBuilder::unattachedFileUids($historyMessages) !== [];
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function hasOpenGenerateImageDraft(array $historyMessages): bool
    {
        foreach ($historyMessages as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : [];
            $tool = (string) ($meta['tool'] ?? ($draft['tool'] ?? ''));
            if (
                $tool === AgentRequestedFiles::GENERATION_TOOL
                && ($meta['type'] ?? '') === 'inline_draft'
                && ($draft['discarded'] ?? false) !== true
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * News create uses write_table (DualMode create-news tools stay agent-hidden without Previewable).
     * Still picks those create tools if they ever appear in the executable catalog.
     *
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function newsCreateTools(array $executableTools, array $alreadyOffered = []): array
    {
        $wanted = ['write_table' => true, 't3ai_create_news_simple' => true, 't3ai_create_news_advanced' => true];
        $picked = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '' || !isset($wanted[$name]) || isset($alreadyOffered[$name]) || isset($picked[$name])) {
                continue;
            }
            $picked[$name] = $tool;
        }

        return array_values($picked);
    }

    /**
     * "Generate SEO metadata for all pages": the whole site, not the open page.
     */
    public static function asksSeoForAllPages(string $query): bool
    {
        $q = mb_strtolower(trim($query));

        return preg_match('/\b(seo|meta\s*(?:data|descriptions?|titles?)?|metadaten|suchmaschinen\w*)\b/u', $q) === 1
            && preg_match('/\b(all|every|whole|entire|alle|s[äa]mtliche|gesamte\w*)\s+(?:\w+\s+){0,2}(pages|seiten|site|website)\b/u', $q) === 1
            && preg_match('/\b(this|current|open|diese|aktuelle)\s+(page|seite)\b/u', $q) !== 1;
    }

    /**
     * "Enable the Mass SEO feature for all pages": queue the pages (an explicit page list ignores the Mass SEO flag).
     */
    public static function isMassSeoEnableRequest(string $query): bool
    {
        $q = mb_strtolower(trim($query));

        return preg_match('/\bmass[\s-]*seo\b/u', $q) === 1
            && preg_match('/\b(enable|activate|turn\s+on|switch\s+on|set\s+up|aktivier\w*|einschalt\w*|aktiv\w*)\b/u', $q) === 1;
    }

    /**
     * Tools of the request that the module cap may have left out.
     *
     * @param list<string> $names
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function toolsByName(array $names, array $executableTools, array $alreadyOffered = []): array
    {
        $wanted = array_flip($names);
        $picked = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name !== '' && isset($wanted[$name]) && !isset($alreadyOffered[$name])) {
                $picked[] = $tool;
            }
        }

        return $picked;
    }

    /**
     * Asks to generate or add schema markup (JSON-LD, structured data, "a Person schema").
     */
    public static function isSchemaRequest(string $query): bool
    {
        $q = mb_strtolower(trim($query));

        return preg_match('/\b(schema|schemas|schema\.org|json-?ld|structured\s+data|strukturierte\s+daten|schema-markup)\b/u', $q) === 1
            && preg_match('/\b(generate|create|add|apply|make|write|insert|generier\w*|erstell\w*|erzeug\w*|hinzuf\w*|anleg\w*)\b/u', $q) === 1
            && preg_match('/\b(delete|remove|l[öo]sch\w*|entfern\w*)\b/u', $q) !== 1;
    }

    /**
     * Keep the schema markup tool in the toolbox for a schema request, even if the module cap left it out.
     *
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, int|string> $alreadyOffered
     * @return list<array<string, mixed>>
     */
    public static function schemaTools(array $executableTools, array $alreadyOffered = []): array
    {
        foreach ($executableTools as $tool) {
            if ((string) ($tool['name'] ?? '') === 't3ai_apply_schema_markup' && !isset($alreadyOffered['t3ai_apply_schema_markup'])) {
                return [$tool];
            }
        }

        return [];
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
     * "Make the German version visible", "hide this page": a change of the hidden field, never a delete.
     */
    public static function isVisibilityRequest(string $query): bool
    {
        $q = mb_strtolower(trim($query));
        if ($q === '' || preg_match('/\b(delete|remove|erase|trash|l[öo]sch\w*|entfern\w*)/u', $q) === 1) {
            return false;
        }

        return preg_match('/\b(visible|invisible|hidden|unhide|hide|activate|deactivate|publish|unpublish|sichtbar|unsichtbar|ausblend\w*|einblend\w*|verstecke\w*|verberg\w*|aktivier\w*|deaktivier\w*|ver[öo]ffentlich\w*)\b/u', $q) === 1;
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

        return preg_match('/\b(change|update|edit|rename|set|rewrite|reword|rephrase|shorten|simplify|[äa]ndere\w*|aktualisier\w*|umbenenn\w*|umschreib\w*|k[üu]rze\w*)\b/u', $q) === 1
            || preg_match('/\bmake\b[^.?!]{0,80}\b(?:shorter|longer|simpler|friendlier|clearer|more\s+(?:formal|casual|friendly|concise|polite)|k[üu]rzer|l[äa]nger|einfacher|freundlicher|klarer)\b/u', $q) === 1;
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
        if ($request === '' || str_starts_with(trim($userMessage), '[The editor ') || AgentPromptBuilder::isGoOnReply($userMessage)) {
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
