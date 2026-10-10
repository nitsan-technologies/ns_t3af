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

use NITSAN\NsT3AF\Agent\Contract\AgentToolTurnExecutorInterface;
use NITSAN\NsT3AF\Mcp\Dto\PreviewResult;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Exception\UnsupportedPlanException;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Shared tool-turn execution for slash/@ fast paths and NL orchestration.
 *
 * @internal
 */
final readonly class AgentToolTurnProcessor implements AgentToolTurnExecutorInterface
{
    /** Characters of a tool response kept in the stored trace. */
    private const TRACE_RESPONSE_CHARS = 1500;

    public function __construct(
        private PermittedActionProvider $permittedActionProvider,
        private AgentDemandCounter $demandCounter,
        private AgentEntitlementExplanation $entitlementExplanation,
        private AgentGovernanceGuard $governanceGuard,
        private AgentTurnRepository $turnRepository,
        private AgentDraftService $draftService,
        private AgentDraftSession $draftSession,
        private AgentToolPlanResolver $toolPlanResolver,
        private McpPlaygroundService $playgroundService,
        private AgentToolResultPresenter $toolResultPresenter,
        private AgentSchedulerHandoff $schedulerHandoff,
        private AgentAuditLogger $auditLogger,
        private AgentToolEditorLabelService $editorLabelService,
        private AgentTranslator $translator,
        private McpToolIntrospectorService $toolIntrospector,
        private ?AgentMediaPreviewService $mediaPreviews = null,
        private ?AgentWorkspaceTarget $workspaceTarget = null,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    public function execute(
        string $toolName,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        $catalog = $this->permittedActionProvider->buildCatalog();
        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $reroutedTool = $this->rerouteWriteTableTool($toolName, $arguments, $catalog);
        if ($reroutedTool !== null) {
            $body['arguments'] = $reroutedTool['arguments'];

            return $this->execute($reroutedTool['tool'], $context, $body, $user, $correlationId);
        }

        $rawTool = $this->findRawTool($toolName);
        if ($rawTool !== null && $this->permittedActionProvider->isHiddenFromAgent($rawTool)) {
            $this->auditLogger->logToolInvocation($correlationId, $toolName, $arguments, false, 0, 'not_available_in_agent');

            return [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.notAvailableInAgent', [$toolName]),
                'meta' => [
                    'type' => 'error',
                    'tool' => $toolName,
                    'correlationId' => $correlationId,
                    'agentHidden' => true,
                ],
            ];
        }

        $tool = $this->findTool($catalog, $toolName);
        if ($tool === null) {
            return [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.unknownTool', [$toolName]),
                'meta' => ['type' => 'error', 'correlationId' => $correlationId],
            ];
        }

        // Content blocked for the group: the generic record tools must not read the same table.
        $tool = $this->contentTableLock($tool, $arguments, $catalog) ?? $tool;

        if (($tool['executable'] ?? false) !== true) {
            // A refused call is part of the audit trail too: the log shows what the editor tried and was denied.
            $this->auditLogger->logToolInvocation($correlationId, (string) ($tool['name'] ?? $toolName), $arguments, false, 0, 'not_permitted');
            $this->demandCounter->recordActivation(
                (string) ($tool['ownerExtensionKey'] ?? ''),
                (string) ($tool['name'] ?? ''),
                (int) ($user->user['uid'] ?? 0),
            );

            return [
                'role' => 'assistant',
                'content' => $this->entitlementExplanation->buildMessage($tool),
                'meta' => array_merge(
                    $this->entitlementExplanation->buildMeta($tool),
                    ['correlationId' => $correlationId],
                ),
            ];
        }

        $toolCallCount = $this->turnRepository->incrementToolCalls($correlationId);
        $guard = $this->governanceGuard->evaluateTurnGuard($toolCallCount);
        $this->turnRepository->updateGuardState($correlationId, $guard['level']);
        if (!$guard['allowed']) {
            return [
                'role' => 'assistant',
                'content' => (string) $guard['message'],
                'meta' => [
                    'type' => 'turn_guard_abort',
                    'correlationId' => $correlationId,
                    'toolCallCount' => $toolCallCount,
                    'orchestratorPause' => true,
                ],
            ];
        }

        $severity = $this->effectiveSeverity($tool, $body);
        $isDualMode = ($tool['dualMode'] ?? false) === true || ($rawTool['dualMode'] ?? false) === true;
        $isPreviewable = ($tool['previewable'] ?? false) === true || ($rawTool['previewable'] ?? false) === true;

        // Read DualMode: native execute, no suggestions card.
        if ($isDualMode && $severity === ToolSeverity::Read->value) {
            return $this->processNativeReadTurn($tool, $context, $body, $user, $correlationId, $toolCallCount, $guard);
        }

        // Write DualMode + Previewable: native preview → suggestions meta (apply in Phase 5).
        if ($isDualMode && $isPreviewable && ($severity === ToolSeverity::Write->value || $severity === ToolSeverity::Destructive->value)) {
            $previewMessage = $this->processPreviewToolTurn($tool, $context, $body, $severity, $correlationId);
            $this->applyTurnGuardMeta($previewMessage['meta'], $guard['message']);
            $previewMessage['meta']['toolCallCount'] = $toolCallCount;

            return $previewMessage;
        }

        if ($severity === ToolSeverity::Write->value || $severity === ToolSeverity::Destructive->value) {
            $draftMessage = $this->processWriteToolTurn($tool, $context, $body, $severity, $correlationId);
            $draftMessage['meta']['correlationId'] = $correlationId;
            $this->applyTurnGuardMeta($draftMessage['meta'], $guard['message']);

            return $draftMessage;
        }

        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $arguments = $this->mergeContextArguments(
            $arguments,
            $context,
            (string) ($tool['name'] ?? ''),
            (string) ($body['provider'] ?? ''),
        );

        $result = $this->playgroundService->invoke($tool['name'], $arguments);
        $invokeSuccess = (bool) ($result['success'] ?? false);
        $this->auditLogger->logToolInvocation(
            $correlationId,
            (string) $tool['name'],
            $arguments,
            $invokeSuccess,
            (int) ($result['latencyMs'] ?? 0),
            $invokeSuccess ? null : 'tool_failed',
        );
        $trace = $this->buildToolTrace(
            (string) $tool['name'],
            $arguments,
            $result,
            $invokeSuccess,
            $user,
        );

        $pageId = (int) ($context['pageId'] ?? 0);
        $presented = $this->toolResultPresenter->present(
            (string) $tool['name'],
            $result['result'] ?? null,
            $invokeSuccess,
            (string) ($result['message'] ?? ''),
            $pageId > 0 ? $pageId : null,
            // Inside the agent loop the model reads the data itself; an extra summary call only costs time.
            // With PII masking on, the raw record data must not be sent to the provider for a summary at all.
            ($body['skipLlmSummary'] ?? false) !== true && !$this->governanceGuard->requiresPiiMasking($user),
        );

        $presented = $this->maskPresented($user, $presented);
        $content = (string) $presented['content'];
        $facts = $presented['facts'];
        $details = $presented['details'];

        $meta = [
            'type' => 'tool_result',
            'tool' => $tool['name'],
            'toolCallLabel' => $this->editorLabelService->resolve($tool),
            'autoRan' => $severity === ToolSeverity::Read->value,
            'severity' => $severity,
            'severityLabel' => (string) ($tool['severityLabel'] ?? ''),
            'success' => (bool) $presented['success'],
            'summary' => (string) $presented['summary'],
            'llmSummary' => $presented['llmSummary'],
            'facts' => $facts,
            'error' => $presented['error'],
            'latencyMs' => (int) ($result['latencyMs'] ?? 0),
            'readWithoutConfirmation' => true,
            'correlationId' => $correlationId,
            'toolCallCount' => $toolCallCount,
            'trace' => $trace,
        ];
        $this->applyTurnGuardMeta($meta, $guard['message']);
        if ($details !== null) {
            $meta['details'] = $details;
        }
        if ($presented['previews'] !== []) {
            $meta['previews'] = $presented['previews'];
        }

        $handoff = $this->schedulerHandoff->buildHandoffMeta(
            $tool,
            $arguments,
            $user,
            (bool) $presented['success'],
        );
        if ($handoff !== null) {
            $meta['schedulerHandoff'] = $handoff;
        }

        return [
            'role' => 'assistant',
            'content' => $content,
            'meta' => $meta,
        ];
    }

    /**
     * record_search / record_count on tt_content read what content_list/content_get/content_search read.
     * When the group blocks those, return the locked content tool so the editor gets the group message.
     *
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $arguments
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array<string, mixed>|null
     */
    private function contentTableLock(array $tool, array $arguments, array $catalog): ?array
    {
        if (!in_array((string) ($tool['name'] ?? ''), ['record_search', 'record_count'], true)) {
            return null;
        }
        if (strtolower(trim((string) ($arguments['tableName'] ?? ''))) !== 'tt_content') {
            return null;
        }
        foreach ($catalog['locked'] as $locked) {
            if (
                in_array((string) ($locked['name'] ?? ''), ['content_list', 'content_get', 'content_search'], true)
                && (string) ($locked['lockKind'] ?? '') === 'group'
            ) {
                return $locked;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRawTool(string $toolName): ?array
    {
        foreach ($this->toolIntrospector->listTools() as $tool) {
            if ((string) ($tool['name'] ?? '') === $toolName) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param array{allowed: bool, level: string, message: string|null} $guard
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function processNativeReadTurn(
        array $tool,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        int $toolCallCount,
        array $guard,
    ): array {
        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $arguments = $this->mergeContextArguments(
            $arguments,
            $context,
            (string) ($tool['name'] ?? ''),
            (string) ($body['provider'] ?? ''),
        );

        $result = $this->playgroundService->invokeWithMode(
            (string) $tool['name'],
            $arguments,
            McpModeResolver::MODE_NATIVE,
        );
        $invokeSuccess = (bool) ($result['success'] ?? false);
        $this->auditLogger->logToolInvocation(
            $correlationId,
            (string) $tool['name'],
            $arguments,
            $invokeSuccess,
            (int) ($result['latencyMs'] ?? 0),
            $invokeSuccess ? null : 'tool_failed',
        );

        $pageId = (int) ($context['pageId'] ?? 0);
        $presented = $this->toolResultPresenter->present(
            (string) $tool['name'],
            $result['result'] ?? null,
            $invokeSuccess,
            (string) ($result['message'] ?? ''),
            $pageId > 0 ? $pageId : null,
            // Inside the agent loop the model reads the data itself; an extra summary call only costs time.
            // With PII masking on, the raw record data must not be sent to the provider for a summary at all.
            ($body['skipLlmSummary'] ?? false) !== true && !$this->governanceGuard->requiresPiiMasking($user),
        );

        $presented = $this->maskPresented($user, $presented);

        $meta = [
            'type' => 'tool_result',
            'tool' => $tool['name'],
            'toolCallLabel' => $this->editorLabelService->resolve($tool),
            'autoRan' => true,
            'severity' => ToolSeverity::Read->value,
            'severityLabel' => (string) ($tool['severityLabel'] ?? ''),
            'success' => (bool) $presented['success'],
            'summary' => (string) $presented['summary'],
            'llmSummary' => $presented['llmSummary'],
            'facts' => $presented['facts'],
            'error' => $presented['error'],
            'latencyMs' => (int) ($result['latencyMs'] ?? 0),
            'readWithoutConfirmation' => true,
            'correlationId' => $correlationId,
            'toolCallCount' => $toolCallCount,
            'routingMode' => McpModeResolver::MODE_NATIVE,
        ];
        $this->applyTurnGuardMeta($meta, $guard['message']);
        if ($presented['details'] !== null) {
            $meta['details'] = $presented['details'];
        }
        if ($presented['previews'] !== []) {
            $meta['previews'] = $presented['previews'];
        }

        return [
            'role' => 'assistant',
            'content' => (string) $presented['content'],
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function processPreviewToolTurn(
        array $tool,
        array $context,
        array $body,
        string $severity,
        string $correlationId,
    ): array {
        $toolName = (string) ($tool['name'] ?? '');
        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $arguments = $this->mergeContextArguments(
            $arguments,
            $context,
            $toolName,
            (string) ($body['provider'] ?? ''),
        );

        $variants = max(1, min(5, (int) ($arguments['variants'] ?? 3)));
        unset($arguments['variants']);

        $result = $this->playgroundService->preview($toolName, $arguments, $variants);
        if (($result['success'] ?? false) !== true || !$result['preview'] instanceof PreviewResult) {
            $failureDetail = AgentPermissionMessage::rewrite(
                (string) ($result['message'] ?? ''),
                $this->translator,
            );
            $this->auditLogger->logToolInvocation($correlationId, $toolName, $arguments, false, 0, mb_substr((string) ($result['message'] ?? ''), 0, 250));

            return [
                'role' => 'assistant',
                'content' => $this->translator->translate(
                    'agent.turn.previewFailed',
                    [$this->editorLabelService->resolve($tool) ?: $toolName, $failureDetail],
                ),
                'meta' => [
                    'type' => 'error',
                    'tool' => $toolName,
                    'correlationId' => $correlationId,
                    'orchestratorPause' => true,
                ],
            ];
        }

        $preview = $result['preview'];
        $editorLabel = $this->editorLabelService->resolve($tool);
        $draftId = bin2hex(random_bytes(8));
        $this->draftSession->storeDraft($draftId, [
            'previewResult' => $preview->toArray(),
            'arguments' => $arguments,
            'severity' => $severity,
            'tool' => $toolName,
            'destructiveArmed' => false,
            'createdAt' => time(),
            'flow' => 'agent_preview',
        ]);

        $this->auditLogger->logToolInvocation(
            $correlationId,
            $toolName,
            array_merge($arguments, ['variants' => $variants, '_preview' => true]),
            true,
            (int) ($result['latencyMs'] ?? 0),
            null,
        );

        $summary = $preview->llmSummary !== ''
            ? $preview->llmSummary
            : $this->translator->translate('agent.suggestions.ready', [$editorLabel, (string) count($preview->variants)]);

        return [
            'role' => 'assistant',
            'content' => $summary,
            'meta' => [
                'type' => 'suggestions',
                'tool' => $toolName,
                'editorLabel' => $editorLabel,
                'severity' => $severity,
                'draftId' => $draftId,
                'suggestions' => $preview->toArray(),
                'previews' => $this->previewsForChange($arguments, $preview->target),
                'callCount' => $preview->callCount,
                'generationPath' => $preview->generationPath,
                'correlationId' => $correlationId,
                'latencyMs' => (int) ($result['latencyMs'] ?? 0),
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * A change that could not be prepared (refused, wrong arguments) still belongs in the log.
     *
     * @param array<string, mixed> $arguments
     */
    private function logRefusedPlan(string $correlationId, string $toolName, array $arguments, \Throwable $exception): void
    {
        $this->auditLogger->logToolInvocation($correlationId, $toolName, $arguments, false, 0, mb_substr($exception->getMessage(), 0, 250));
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function processWriteToolTurn(array $tool, array $context, array $body, string $severity, string $correlationId = ''): array
    {
        $toolName = (string) ($tool['name'] ?? '');
        if (!$this->toolPlanResolver->supportsPlanning($toolName)) {
            return [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.planUnsupported', [$toolName]),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'orchestratorPause' => true],
            ];
        }

        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $arguments = $this->mergeContextArguments(
            $arguments,
            $context,
            $toolName,
            (string) ($body['provider'] ?? ''),
        );
        /** @var list<array<string, mixed>> $history */
        $history = is_array($body['conversationHistory'] ?? null) ? array_values($body['conversationHistory']) : [];
        $arguments = $this->normalizeWriteToolArguments($toolName, $arguments);
        $arguments = $this->enrichPlannedToolArguments($toolName, $arguments, $history);
        $requestQuery = trim((string) ($body['requestQuery'] ?? ''));
        if ($toolName === 'write_table' && $requestQuery !== '') {
            $arguments['requestQuery'] = $requestQuery;
        }

        try {
            $plan = $this->toolPlanResolver->plan($toolName, $arguments);
        } catch (UnsupportedPlanException $exception) {
            $this->logRefusedPlan($correlationId, $toolName, $arguments, $exception);

            return [
                'role' => 'assistant',
                'content' => $exception->getMessage(),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'orchestratorPause' => true],
            ];
        } catch (\InvalidArgumentException $exception) {
            // Wrong arguments (a record that does not exist, a wrong field): no card, and the turn
            // goes on so the model can correct the call.
            $this->logRefusedPlan($correlationId, $toolName, $arguments, $exception);

            return [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.planInvalid', [$exception->getMessage()]),
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => $toolName,
                    'toolCallLabel' => $this->editorLabelService->resolve($tool),
                    'severity' => $severity,
                    'success' => false,
                    'error' => $exception->getMessage(),
                    'autoRan' => false,
                    'facts' => [],
                ],
            ];
        } catch (\Throwable $exception) {
            $this->logRefusedPlan($correlationId, $toolName, $arguments, $exception);

            return [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.planFailed', [$toolName, $exception->getMessage()]),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'orchestratorPause' => true],
            ];
        }

        $shownTool = $plan->toolName !== '' && $plan->toolName !== $toolName ? $plan->toolName : $toolName;
        $editorLabel = $shownTool === $toolName
            ? $this->editorLabelService->resolve($tool)
            : $this->editorLabelService->resolveByName($shownTool);
        $draftCard = $this->draftService->buildDraftCard($plan, $severity);
        $draftCard['editorLabel'] = $editorLabel;
        $this->draftService->persistDraft($draftCard, $plan, $arguments, $this->draftSession);

        $content = ($draftCard['kind'] ?? '') === SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION
            ? (string) ($draftCard['summary'] ?? $this->translator->translate('agent.draft.proposed', [$editorLabel]))
            : $this->translator->translate('agent.draft.proposed', [$editorLabel]);

        return [
            'role' => 'assistant',
            'content' => $content,
            'meta' => [
                'type' => 'inline_draft',
                'tool' => $shownTool,
                'editorLabel' => $editorLabel,
                'severity' => $severity,
                'draft' => $draftCard,
                'previews' => $this->previewsForChange($arguments, []),
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * The image a prepared change is about (alt text / metadata of a file), shown on the card.
     *
     * @param array<string, mixed> $arguments
     * @param array<mixed> $target
     * @return list<array{fileUid: int, url: string, href: string, name: string, alt: string}>
     */
    private function previewsForChange(array $arguments, array $target): array
    {
        if (!isset($this->mediaPreviews)) {
            return [];
        }
        $previews = $this->mediaPreviews->forRecord((string) ($target['table'] ?? ''), (int) ($target['uid'] ?? 0));

        return $previews !== [] ? $previews : $this->mediaPreviews->forDetails($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function mergeContextArguments(
        array $arguments,
        array $context,
        string $toolName = '',
        string $providerIdentifier = '',
    ): array {
        // An id and a URL for the same target: the id (e.g. from an applied result) wins, a guessed URL must not contradict it.
        foreach ([['pageId', 'pageUrl'], ['parentPageId', 'parentPageUrl']] as [$idKey, $urlKey]) {
            if ((int) ($arguments[$idKey] ?? 0) > 0 && trim((string) ($arguments[$urlKey] ?? '')) !== '') {
                unset($arguments[$urlKey]);
            }
        }

        // Models often call the search word "query" (or "q"); these tools only know "search", and a
        // missing word must not turn into an empty result.
        if (in_array(strtolower(trim($toolName)), ['pages_search', 'content_search', 'record_search'], true)) {
            foreach (['query', 'q', 'term', 'keyword', 'text'] as $alias) {
                if (trim((string) ($arguments['search'] ?? '')) === '' && isset($arguments[$alias]) && is_string($arguments[$alias])) {
                    $arguments['search'] = $arguments[$alias];
                }
                unset($arguments[$alias]);
            }
        }

        // The editor named a page, or picked one after "I can't find that page". That page wins
        // over the page on screen and over a page id the model guessed.
        $lockedPageId = (int) ($context['lockedPageId'] ?? 0);
        if ($lockedPageId > 0) {
            $context['pageId'] = $lockedPageId;
            unset($arguments['pageId'], $arguments['pageUrl']);
        }

        $pageId = (int) ($context['pageId'] ?? 0);
        if (strtolower(trim($toolName)) === 't3ai_mass_seo_queue_add') {
            $fromUrl = self::pageIdsWrittenAsUrl((string) ($arguments['pageUrl'] ?? ''));
            if ($fromUrl !== [] && !self::hasExplicitPageIdList($arguments['pageIds'] ?? null)) {
                $arguments['pageIds'] = $fromUrl;
                unset($arguments['pageUrl']);
            }
        }
        // The model named another page by URL: the page on screen must not be added as a second, conflicting target.
        $namesPageByUrl = trim((string) ($arguments['pageUrl'] ?? '')) !== '' && !isset($arguments['pageId']);
        // An explicit SEO queue list is the whole request. The open page must not replace it.
        $namesSeoQueuePages = strtolower(trim($toolName)) === 't3ai_mass_seo_queue_add'
            && self::hasExplicitPageIdList($arguments['pageIds'] ?? null);
        if (
            $pageId > 0
            && !$namesPageByUrl
            && !$namesSeoQueuePages
            && !$this->toolIgnoresLayoutPageContext($toolName)
        ) {
            $arguments['pageId'] ??= $pageId;
            // Do not force pid onto *_search tools — that scoped site-wide searches to the current page only.
            if (!$this->toolUsesOptionalSearchPid($toolName)) {
                $arguments['pid'] ??= $pageId;
            }
            // Only for tools whose `uid` means pages.uid (never content_get / other entity uids).
            if ($this->toolUsesPageIdAsUid($toolName)) {
                $arguments['uid'] ??= $pageId;
            }
        }

        // News lives in storage folders, not on the Layout page. Models often send the open page
        // as pid (or 0); that empties the search. Drop those so record_search is site-wide (page access).
        if (strtolower(trim($toolName)) === 'record_search') {
            $table = strtolower(trim((string) ($arguments['tableName'] ?? $arguments['table'] ?? '')));
            $pidArg = array_key_exists('pid', $arguments) ? (int) $arguments['pid'] : -1;
            if ($pidArg <= 0) {
                unset($arguments['pid']);
            } elseif (
                $table === 'tx_news_domain_model_news'
                && $pageId > 0
                && $pidArg === $pageId
            ) {
                unset($arguments['pid']);
            }
        }

        $languageId = (int) ($context['languageId'] ?? 0);
        if ($languageId > 0) {
            $arguments['targetLanguageUid'] ??= $languageId;
        }

        $record = is_array($context['record'] ?? null) ? $context['record'] : null;
        if ($record !== null) {
            if (isset($record['uid'])) {
                $arguments['uid'] ??= (int) $record['uid'];
            }
            if (isset($record['table'])) {
                $arguments['table'] ??= (string) $record['table'];
            }
        }

        // The workspace follows the editor: the one they work in, otherwise "MCP Server > Workspace
        // selection" (Live when none is chosen). It is applied for this call only and never replaced
        // by a model-supplied value; the workspace_* tools take their own workspaceId argument.
        if (!str_starts_with(strtolower(trim($toolName)), 'workspace_')) {
            $workspaceId = (int) ($context['workspaceId'] ?? 0);
            $beUser = $GLOBALS['BE_USER'] ?? null;
            if (isset($this->workspaceTarget) && $beUser instanceof BackendUserAuthentication) {
                $workspaceId = $this->workspaceTarget->resolve($workspaceId, $beUser);
            }
            unset($arguments['workspaceId']);
            if ($workspaceId > 0) {
                $arguments['workspaceId'] = $workspaceId;
            }
        }

        $storageUid = (int) ($context['storageUid'] ?? 0);
        if ($storageUid > 0) {
            $arguments['storageUid'] ??= $storageUid;
        }

        $module = trim((string) ($context['module'] ?? ''));
        if ($module !== '') {
            $arguments['module'] ??= $module;
        }

        // Agent provider selection → MCP McpInvocationContext (`aiProvider` arg).
        $provider = trim($providerIdentifier);
        if (
            $provider !== ''
            && $provider !== AgentProviderOptions::DEFAULT
            && (!isset($arguments['aiProvider']) || trim((string) $arguments['aiProvider']) === '')
        ) {
            $arguments['aiProvider'] = $provider;
        }

        return $arguments;
    }

    private static function hasExplicitPageIdList(mixed $pageIds): bool
    {
        if (is_string($pageIds)) {
            $pageIds = preg_split('/[\s,;]+/', trim($pageIds, " \t\n\r\0\x0B\"'[]")) ?: [];
        }
        if (!is_array($pageIds)) {
            return false;
        }
        foreach ($pageIds as $pageId) {
            if ((int) $pageId > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function pageIdsWrittenAsUrl(string $pageUrl): array
    {
        $trimmed = trim($pageUrl);
        if ($trimmed === '' || preg_match('/^\d+(?:[\s,;]+\d+)*$/', $trimmed) !== 1) {
            return [];
        }

        $ids = [];
        foreach (preg_split('/[\s,;]+/', $trimmed) ?: [] as $pageId) {
            $pageId = (int) $pageId;
            if ($pageId > 0) {
                $ids[$pageId] = $pageId;
            }
        }

        return array_values($ids);
    }

    private function toolUsesOptionalSearchPid(string $toolName): bool
    {
        $toolName = strtolower(trim($toolName));

        return $toolName !== '' && str_ends_with($toolName, '_search');
    }

    /**
     * Layout page context (open page in Page module) must not label file-only satellite steps.
     */
    private function toolIgnoresLayoutPageContext(string $toolName): bool
    {
        return match (strtolower(trim($toolName))) {
            't3ai_generate_image' => true,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function enrichPlannedToolArguments(string $toolName, array $arguments, array $history): array
    {
        $toolName = strtolower(trim($toolName));
        if ($toolName === 't3ai_generate_image') {
            unset($arguments['pageId'], $arguments['pid']);
            $newsUid = AgentPromptBuilder::latestAppliedNewsUid($history);
            if ($newsUid !== null) {
                $arguments['newsArticleUid'] = $newsUid;
            }

            return $arguments;
        }

        if ($toolName === 't3aa_update_file_metadata') {
            return $this->enrichFileMetadataArguments($arguments, $history);
        }

        if ($toolName !== 'file_reference_add') {
            return $arguments;
        }

        if (AgentPromptBuilder::hasOpenFileReferenceDraft($history)) {
            throw new \InvalidArgumentException(
                'An attach card is already open for the editor. Wait until they Apply or Decline it; do not prepare another attach.',
            );
        }

        $fileUidsRaw = trim((string) ($arguments['fileUids'] ?? ''));
        if ($fileUidsRaw !== '' && !self::fileUidsArgumentIsNumericList($fileUidsRaw)) {
            $resolved = AgentPromptBuilder::unattachedFileUids($history);
            if ($resolved === []) {
                throw new \InvalidArgumentException(
                    'No generated file is available yet. Call t3ai_generate_image first and wait until the editor applies it, then attach using the numeric sys_file uid from that result.',
                );
            }
            $arguments['fileUids'] = implode(',', array_map(static fn(int $uid): string => (string) $uid, $resolved));
            $fileUidsRaw = (string) $arguments['fileUids'];
        }

        $pending = AgentPromptBuilder::unattachedFileUids($history);
        if ($pending === []) {
            throw new \InvalidArgumentException(
                'Every generated file in this conversation is already attached (or none exists yet). Do not call file_reference_add again.',
            );
        }
        if ($fileUidsRaw !== '' && self::fileUidsArgumentIsNumericList($fileUidsRaw)) {
            $requested = [];
            foreach (explode(',', $fileUidsRaw) as $part) {
                $uid = (int) trim($part);
                if ($uid > 0) {
                    $requested[] = $uid;
                }
            }
            $stillOpen = array_values(array_intersect($requested, $pending));
            if ($stillOpen === []) {
                throw new \InvalidArgumentException(
                    'Those files are already attached. Do not attach the same file again.',
                );
            }
            $arguments['fileUids'] = implode(',', array_map(static fn(int $uid): string => (string) $uid, $stillOpen));
        }

        $newsUid = AgentPromptBuilder::latestAppliedNewsUid($history);
        if ($newsUid !== null) {
            $table = strtolower(trim((string) ($arguments['table'] ?? '')));
            if ($table === '' || $table === 'tx_news_domain_model_news') {
                $arguments['table'] = 'tx_news_domain_model_news';
                if ((int) ($arguments['uid'] ?? 0) <= 0) {
                    $arguments['uid'] = $newsUid;
                }
                $arguments['fieldName'] ??= 'fal_media';
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function enrichFileMetadataArguments(array $arguments, array $history): array
    {
        $fileUid = (int) ($arguments['fileUid'] ?? 0);
        $fileUrl = trim((string) ($arguments['fileUrl'] ?? ''));
        if ($fileUid > 0) {
            unset($arguments['fileUrl']);

            return $arguments;
        }

        if ($fileUrl !== '' && !self::isPlaceholderFileLocator($fileUrl)) {
            return $arguments;
        }

        $resolved = AgentPromptBuilder::unattachedFileUids($history);
        if ($resolved === []) {
            // Latest generate may already be attached; still allow editing its texts by uid.
            $resolved = self::latestGeneratedFileUids($history);
        }
        if ($resolved === []) {
            throw new \InvalidArgumentException(
                'No sys_file uid is available for image texts. Pass fileUid from t3ai_generate_image (or file_list), not a placeholder path like /generated-image.jpg.',
            );
        }
        $arguments['fileUid'] = $resolved[0];
        unset($arguments['fileUrl']);

        return $arguments;
    }

    private static function isPlaceholderFileLocator(string $value): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return true;
        }

        return (bool) preg_match(
            '#^(?:\[?image(?:uid|id|url)?\]?|/generated[-_]?image\.(?:jpg|jpeg|png|webp)|generated[-_]?image\.(?:jpg|jpeg|png|webp)|placeholder)$#',
            $value,
        );
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<int>
     */
    private static function latestGeneratedFileUids(array $history): array
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') !== 'assistant'
                || ($meta['type'] ?? '') !== 'tool_result'
                || ($meta['success'] ?? true) === false
                || (string) ($meta['tool'] ?? '') !== 't3ai_generate_image'
            ) {
                continue;
            }
            $uids = [];
            $fileUid = (int) (($meta['details']['fileUid'] ?? 0));
            if ($fileUid > 0) {
                $uids[] = $fileUid;
            }
            foreach (is_array($meta['previews'] ?? null) ? $meta['previews'] : [] as $preview) {
                if (is_array($preview) && (int) ($preview['fileUid'] ?? 0) > 0) {
                    $uids[] = (int) $preview['fileUid'];
                }
            }
            if ($uids !== []) {
                return array_values(array_unique($uids));
            }
        }

        return [];
    }

    private static function fileUidsArgumentIsNumericList(string $fileUids): bool
    {
        foreach (explode(',', $fileUids) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^[1-9]\d*$/', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tools where the primary `uid` argument is a pages row (same value as context pageId).
     */
    private function toolUsesPageIdAsUid(string $toolName): bool
    {
        return match (strtolower(trim($toolName))) {
            'pages_get', 'pages_copy' => true,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    private function rerouteWriteTableTool(string $toolName, array $arguments, array $catalog): ?array
    {
        if ($toolName !== 'write_table') {
            return null;
        }

        $table = strtolower(trim((string) ($arguments['tableName'] ?? $arguments['table'] ?? '')));
        if ($table !== 'sys_file_metadata') {
            return null;
        }

        if ($this->findTool($catalog, 't3aa_update_file_metadata') === null) {
            return null;
        }

        $fileUid = (int) ($arguments['uid'] ?? $arguments['fileUid'] ?? 0);

        return [
            'tool' => 't3aa_update_file_metadata',
            'arguments' => $fileUid > 0 ? ['fileUid' => $fileUid] : [],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function normalizeWriteToolArguments(string $toolName, array $arguments): array
    {
        if ($toolName !== 'write_table') {
            return $arguments;
        }

        $action = strtolower(trim((string) ($arguments['action'] ?? '')));
        if ($action === 'write') {
            $arguments['action'] = 'update';
        }

        $table = strtolower(trim((string) ($arguments['tableName'] ?? $arguments['table'] ?? '')));
        if ($table !== '' && !isset($arguments['tableName'])) {
            $arguments['tableName'] = $table;
        }

        return $arguments;
    }

    /**
     * The tool response in the trace, shortened: the full data is already in the result's details
     * and is stored with the conversation only once.
     */
    private static function traceResponse(mixed $response): mixed
    {
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($encoded) || mb_strlen($encoded) <= self::TRACE_RESPONSE_CHARS) {
            return $response;
        }

        return mb_substr($encoded, 0, self::TRACE_RESPONSE_CHARS) . '… (shortened)';
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private function buildToolTrace(
        string $toolName,
        array $arguments,
        array $result,
        bool $invokeSuccess,
        BackendUserAuthentication $user,
    ): array {
        $trace = [[
            'step' => 'invoke',
            'tool' => $toolName,
            'request' => $arguments,
            'response' => $invokeSuccess
                ? self::traceResponse($result['result'] ?? null)
                : ['error' => (string) ($result['message'] ?? 'tool_failed')],
            'latencyMs' => (int) ($result['latencyMs'] ?? 0),
            'status' => $invokeSuccess ? 'ok' : 'error',
        ]];

        if (!$this->governanceGuard->requiresPiiMasking($user)) {
            return $trace;
        }

        try {
            $encoded = json_encode($trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $masked = $this->governanceGuard->maskPii($encoded);
            $decoded = json_decode($masked, true);

            return is_array($decoded) ? array_values($decoded) : $trace;
        } catch (\JsonException) {
            return $trace;
        }
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array<string, mixed>|null
     */
    private function findTool(array $catalog, string $toolName): ?array
    {
        $needle = strtolower(trim($toolName));
        foreach ([$catalog['executable'], $catalog['locked']] as $group) {
            foreach ($group as $tool) {
                if (strtolower((string) ($tool['name'] ?? '')) === $needle) {
                    return $tool;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function applyTurnGuardMeta(array &$meta, ?string $message): void
    {
        if ($message !== null && $message !== '') {
            $meta['turnGuardWarning'] = $message;
        }
    }

    /**
     * Masks a presented tool result once, right after presenting and before any of it is used.
     *
     * @param array<string, mixed> $presented
     * @return array<string, mixed>
     */
    private function maskPresented(BackendUserAuthentication $user, array $presented): array
    {
        return $this->governanceGuard->requiresPiiMasking($user)
            ? $this->governanceGuard->maskPresentedResult($presented)
            : $presented;
    }

    /**
     * Declared severity, raised to Destructive for a delete through write_table,
     * so the draft needs the two-step confirmation like other destructive tools.
     *
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $body
     */
    private function effectiveSeverity(array $tool, array $body): string
    {
        $severity = (string) ($tool['severity'] ?? '');
        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        if (
            ($tool['name'] ?? '') === 'write_table'
            && strtolower(trim((string) ($arguments['action'] ?? ''))) === 'delete'
        ) {
            return ToolSeverity::Destructive->value;
        }

        return $severity;
    }
}
