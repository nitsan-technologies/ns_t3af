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
use NITSAN\NsT3AF\Mcp\Exception\NoChangeRequiredException;
use NITSAN\NsT3AF\Mcp\Exception\UnsupportedPlanException;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use NITSAN\NsT3AF\Mcp\Service\McpModeResolver;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared tool-turn execution for slash/@ fast paths and NL orchestration.
 *
 * @internal
 */
final readonly class AgentToolTurnProcessor implements AgentToolTurnExecutorInterface
{
    /** Characters of a tool response kept in the stored trace. */
    private const TRACE_RESPONSE_CHARS = 1500;

    /** Queue tools whose rows are read for the whole site, so a draft workspace must not add to them. */
    private const SITE_WIDE_QUEUE_TOOLS = [
        't3ai_mass_seo_queue_add',
        't3ai_mass_translation_queue_add',
    ];

    /** Tools that create pages under a parent page (argument parentPageId). */
    private const PARENT_PAGE_TOOLS = [
        't3ai_create_page_simple',
        't3ai_create_page_advanced',
        't3ai_create_page_structure',
        't3ai_create_blog_simple',
        't3ai_create_blog_advanced',
    ];

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

        $requestQuery = trim((string) ($body['requestQuery'] ?? ''));
        $arguments = self::openPageAsParent($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $arguments = self::withEditorRequestAsStructurePrompt($arguments, $toolName, $requestQuery);
        $languageTurn = $this->unavailableLanguageTurn($toolName, $context, $requestQuery, $correlationId);
        if ($languageTurn !== null) {
            return $languageTurn;
        }
        $translateClarify = self::clarifyAmbiguousTranslateContent(
            $toolName,
            $arguments,
            (int) ($context['pageId'] ?? 0),
            $requestQuery,
            $correlationId,
        );
        if ($translateClarify !== null) {
            return $translateClarify;
        }
        $arguments = self::pinTranslateContentToOpenPage(
            $arguments,
            $toolName,
            (int) ($context['pageId'] ?? 0),
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
        $summary .= $this->translationProviderNotice($toolName, $arguments, (string) ($body['requestQuery'] ?? ''));

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
        $requestQuery = trim((string) ($body['requestQuery'] ?? ''));
        $arguments = self::seoQueueRemovePageIds($arguments, $toolName, $requestQuery);
        $arguments = self::seoQueueAddRecursiveSubtree($arguments, $toolName, $requestQuery);
        $arguments = self::openPageAsParent($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $arguments = self::openPageAsCreatePid($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $arguments = self::pinOpenPageForQueueRemove($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $gateRequest = trim((string) ($body['requestForGate'] ?? ''));
        $arguments = $this->enrichPlannedToolArguments($toolName, $arguments, $history, $gateRequest !== '' ? $gateRequest : $requestQuery);
        if ($toolName === 'write_table' && $requestQuery !== '') {
            $arguments['requestQuery'] = $requestQuery;
        }
        $refuseRemove = self::refuseQueueRemoveOnAddRequest($toolName, $requestQuery);
        if ($refuseRemove !== null) {
            return [
                'role' => 'assistant',
                'content' => $refuseRemove,
                'meta' => [
                    'type' => 'info',
                    'tool' => $toolName,
                    'success' => true,
                    'orchestratorPause' => true,
                    'correlationId' => $correlationId,
                ],
            ];
        }
        $refuseNamedOpen = self::refuseSeoQueueOpenPageForNamedTitle(
            $toolName,
            $arguments,
            $context,
            $requestQuery,
        );
        if ($refuseNamedOpen !== null) {
            return [
                'role' => 'assistant',
                'content' => $refuseNamedOpen,
                'meta' => [
                    'type' => 'error',
                    'tool' => $toolName,
                    'orchestratorPause' => true,
                    'correlationId' => $correlationId,
                ],
            ];
        }
        $translateClarify = self::clarifyAmbiguousTranslateContent(
            $toolName,
            $arguments,
            (int) ($context['pageId'] ?? 0),
            $requestQuery,
            $correlationId,
        );
        if ($translateClarify !== null) {
            return $translateClarify;
        }
        $arguments = self::pinTranslateContentToOpenPage(
            $arguments,
            $toolName,
            (int) ($context['pageId'] ?? 0),
        );
        if (in_array(strtolower($toolName), self::SITE_WIDE_QUEUE_TOOLS, true)) {
            $workspaceUser = $GLOBALS['BE_USER'] ?? null;
            if ($workspaceUser instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication && (int) $workspaceUser->workspace > 0) {
                $message = $this->translator->translate('agent.queue.draftWorkspace');
                if ($message === 'agent.queue.draftWorkspace') {
                    $message = 'The queues are shared by the whole site and cannot be changed from a draft workspace. Switch to the Live workspace first. Nothing was added.';
                }

                return [
                    'role' => 'assistant',
                    'content' => $message,
                    'meta' => ['type' => 'error', 'tool' => $toolName, 'correlationId' => $correlationId, 'orchestratorPause' => true],
                ];
            }
        }
        if (strtolower($toolName) === 't3ai_mass_translation_queue_requeue') {
            $arguments = self::requeueFailedOnly($arguments, $requestQuery);
        }
        $languageTurn = $this->unavailableLanguageTurn($toolName, $context, $requestQuery, $correlationId);
        if ($languageTurn !== null) {
            return $languageTurn;
        }
        $parsedGlossaryPair = null;
        if (strtolower($toolName) === 't3ai_glossary_save') {
            $parsedGlossaryPair = GlossaryPairRequest::parse($requestQuery);
            if ($parsedGlossaryPair !== null) {
                $arguments['sourceTerm'] = $parsedGlossaryPair['source'];
                $arguments['targetTerm'] = $parsedGlossaryPair['target'];
            }
            $siteLanguages = GlossarySaveLanguage::siteLanguages($context);
            $choice = GlossarySaveLanguage::resolve($requestQuery, $siteLanguages);
            $options = $choice['options'] ?? null;
            if (isset($choice['languageUid'])) {
                $arguments['languageUid'] = (int) $choice['languageUid'];
            } elseif ($parsedGlossaryPair !== null && is_array($options)) {
                unset($arguments['languageUid']);
            } elseif ($parsedGlossaryPair !== null && (int) ($arguments['languageUid'] ?? 0) <= 0) {
                $targets = array_values(array_filter(
                    $siteLanguages,
                    static fn(array $language): bool => (int) ($language['id'] ?? 0) > 0,
                ));
                if (count($targets) === 1) {
                    $arguments['languageUid'] = (int) $targets[0]['id'];
                }
            }
            if (is_array($options) && $parsedGlossaryPair === null) {
                return $this->glossaryLanguageQuestion(array_values(array_filter($options, 'is_string')), $correlationId);
            }
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
        } catch (NoChangeRequiredException $exception) {
            return [
                'role' => 'assistant',
                'content' => $exception->getMessage(),
                'meta' => [
                    'type' => 'info',
                    'tool' => $toolName,
                    'success' => true,
                    'orchestratorPause' => true,
                ],
            ];
        } catch (\InvalidArgumentException $exception) {
            if (
                $parsedGlossaryPair !== null
                && str_contains($exception->getMessage(), 'languageUid')
            ) {
                $options = GlossarySaveLanguage::resolve($requestQuery, GlossarySaveLanguage::siteLanguages($context))['options'] ?? null;
                if (is_array($options)) {
                    return $this->glossaryLanguageQuestion(array_values(array_filter($options, 'is_string')), $correlationId);
                }
            }
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
        $content .= $this->translationProviderNotice($toolName, $arguments, $requestQuery);

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
     * A new page made with write_table needs a place. Without a pid (an editor's group cannot insert on page 0) or
     * with the site root the model took for "Home", the page belongs under the open page, unless the request says
     * "root" or "top level".
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function openPageAsCreatePid(array $arguments, int $openPageId, string $toolName, string $requestQuery): array
    {
        if ($openPageId <= 0 || strtolower(trim($toolName)) !== 'write_table') {
            return $arguments;
        }
        $table = strtolower(trim((string) ($arguments['tableName'] ?? $arguments['table'] ?? '')));
        if ($table !== 'pages' || strtolower(trim((string) ($arguments['action'] ?? ''))) !== 'create') {
            return $arguments;
        }
        if (isset($arguments['beforeUid']) || isset($arguments['afterUid'])) {
            return $arguments;
        }
        $data = $arguments['data'] ?? [];
        $wasString = is_string($data);
        if ($wasString) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($data) || isset($data['beforeUid']) || isset($data['afterUid'])) {
            return $arguments;
        }
        $pid = isset($data['pid']) && is_numeric($data['pid']) ? (int) $data['pid'] : 0;
        $useOpenPage = $pid <= 0;
        if (
            !$useOpenPage
            && $pid !== $openPageId
            && preg_match('/\b(root|top[- ]?level|site\s*root|wurzel|oberste\w*)\b/iu', $requestQuery) !== 1
        ) {
            try {
                $parent = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('pages', $pid, 'pid,is_siteroot');
            } catch (\Throwable) {
                $parent = null;
            }
            $useOpenPage = is_array($parent) && ((int) ($parent['pid'] ?? 1) === 0 || (int) ($parent['is_siteroot'] ?? 0) === 1);
        }
        if (!$useOpenPage) {
            return $arguments;
        }
        $data['pid'] = $openPageId;
        $arguments['data'] = $wasString ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $data;

        return $arguments;
    }

    /**
     * The page tree is written from the prompt alone. A model that sends a short prompt ("Home") for
     * "a structure with Home, About, Services, Contact" gets a tree with one page, so the editor's own
     * request is part of the prompt.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function withEditorRequestAsStructurePrompt(array $arguments, string $toolName, string $requestQuery): array
    {
        if (strtolower(trim($toolName)) !== 't3ai_create_page_structure' || $requestQuery === '') {
            return $arguments;
        }
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt !== '' && mb_stripos($prompt, $requestQuery) !== false) {
            return $arguments;
        }
        if ($prompt !== '' && mb_strlen($prompt) >= mb_strlen($requestQuery)) {
            return $arguments;
        }
        $arguments['prompt'] = trim($requestQuery . ($prompt !== '' ? "\n" . $prompt : ''));

        return $arguments;
    }

    /**
     * The model reads "Home" in "a structure with Home, About, Services" as the existing start page and
     * sends it as the parent. Without a word like "root" or "top level", the pages belong under the open page.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function openPageAsParent(array $arguments, int $openPageId, string $toolName, string $requestQuery): array
    {
        $parentId = (int) ($arguments['parentPageId'] ?? 0);
        if (
            $openPageId <= 0
            || $parentId <= 0
            || $parentId === $openPageId
            || !in_array(strtolower(trim($toolName)), self::PARENT_PAGE_TOOLS, true)
            || preg_match('/\b(root|top[- ]?level|site\s*root|wurzel|oberste\w*)\b/iu', $requestQuery) === 1
        ) {
            return $arguments;
        }
        try {
            $parent = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('pages', $parentId, 'pid,is_siteroot');
        } catch (\Throwable) {
            return $arguments;
        }
        if (is_array($parent) && ((int) ($parent['pid'] ?? 1) === 0 || (int) ($parent['is_siteroot'] ?? 0) === 1)) {
            $arguments['parentPageId'] = $openPageId;
        }

        return $arguments;
    }

    /**
     * "Remove page 138" keeps that uid. A request with no number still uses the open page.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function seoQueueRemovePageIds(array $arguments, string $toolName, string $requestQuery): array
    {
        if (strtolower(trim($toolName)) !== 't3ai_mass_seo_queue_remove') {
            return $arguments;
        }
        $named = AgentTargetPageResolver::mentionedPageUids($requestQuery);
        if ($named !== []) {
            $arguments['pageIds'] = $named;
        }

        return $arguments;
    }

    /**
     * "Home and all its subpages" must use pageId + recursive (Mass SEO filter), never an invented pageIds tree.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function seoQueueAddRecursiveSubtree(array $arguments, string $toolName, string $requestQuery): array
    {
        if (strtolower(trim($toolName)) !== 't3ai_mass_seo_queue_add') {
            return $arguments;
        }
        if (preg_match(
            '/\b(all\s+(?:its\s+|the\s+)?subpages?|and\s+all\s+(?:its\s+)?subpages?|und\s+alle\s+unterseiten)\b/iu',
            $requestQuery,
        ) !== 1) {
            return $arguments;
        }
        // The invented list is dropped; the first named page stays the root when no pageId was given.
        $firstListed = 0;
        $listed = $arguments['pageIds'] ?? null;
        if (is_string($listed)) {
            $listed = preg_split('/[\s,;]+/', trim($listed, " \t\n\r\0\x0B\"'[]")) ?: [];
        }
        if (is_array($listed)) {
            foreach ($listed as $listedId) {
                if ((int) $listedId > 0) {
                    $firstListed = (int) $listedId;
                    break;
                }
            }
        }
        unset($arguments['pageIds']);
        if ((int) ($arguments['pageId'] ?? 0) <= 0 && trim((string) ($arguments['pageUrl'] ?? '')) === '' && $firstListed > 0) {
            $arguments['pageId'] = $firstListed;
        }
        $arguments['recursive'] = true;

        return $arguments;
    }

    /**
     * A named page for the SEO/Google list must not silently queue the open Layout page.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    public static function refuseSeoQueueOpenPageForNamedTitle(
        string $toolName,
        array $arguments,
        array $context,
        string $requestQuery,
    ): ?string {
        if (strtolower(trim($toolName)) !== 't3ai_mass_seo_queue_add') {
            return null;
        }
        $named = AgentTargetPageResolver::namedPageTitle($requestQuery);
        if ($named === null) {
            return null;
        }
        $lockedPageId = (int) ($context['lockedPageId'] ?? 0);
        if ($lockedPageId > 0) {
            return null;
        }
        $openPageId = (int) ($context['pageId'] ?? 0);
        $targetPageId = (int) ($arguments['pageId'] ?? 0);
        if ($targetPageId <= 0 && self::hasExplicitPageIdList($arguments['pageIds'] ?? null)) {
            return null;
        }
        if ($openPageId > 0 && ($targetPageId === $openPageId || $targetPageId <= 0)) {
            return sprintf(
                'I need the page "%s", not the page that is open. Nothing was queued.',
                $named,
            );
        }

        return null;
    }

    /**
     * Translate-content must target an element on the open page. A guessed uid from another page is dropped;
     * the only element on this page is used when there is exactly one.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function pinTranslateContentToOpenPage(array $arguments, string $toolName, int $openPageId): array
    {
        if (strtolower(trim($toolName)) !== 't3ai_translate_content' || $openPageId <= 0) {
            return $arguments;
        }
        $arguments['pageId'] = $openPageId;
        $contentUid = (int) ($arguments['contentUid'] ?? 0);
        if ($contentUid > 0) {
            $pid = self::contentElementPid($contentUid);
            if ($pid === $openPageId) {
                return $arguments;
            }
            unset($arguments['contentUid']);
        }
        $only = self::soleDefaultLanguageContentUid($openPageId);
        if ($only > 0) {
            $arguments['contentUid'] = $only;
        }

        return $arguments;
    }

    private static function contentElementPid(int $contentUid): int
    {
        if ($contentUid <= 0) {
            return 0;
        }
        try {
            $row = BackendUtility::getRecord('tt_content', $contentUid, 'uid,pid');
        } catch (\Throwable) {
            return 0;
        }

        return is_array($row) ? (int) ($row['pid'] ?? 0) : 0;
    }

    /**
     * Several elements share the named title on this page — ask which one (buttons).
     *
     * @param array<string, mixed> $arguments
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    public static function clarifyAmbiguousTranslateContent(
        string $toolName,
        array $arguments,
        int $openPageId,
        string $requestQuery,
        string $correlationId,
    ): ?array {
        if (strtolower(trim($toolName)) !== 't3ai_translate_content' || $openPageId <= 0 || $requestQuery === '') {
            return null;
        }
        $needle = '';
        if (preg_match('/["“]([^"”]{2,80})["”]/u', $requestQuery, $quoted) === 1) {
            $needle = trim((string) $quoted[1]);
        } elseif (preg_match(
            '/\b(?:element|inhaltselement)\s+["“]?([^"”\n,]{2,80}?)["”]?\s+(?:on|into|to|nach|ins|auf)\b/iu',
            $requestQuery,
            $quoted,
        ) === 1) {
            $needle = trim((string) $quoted[1]);
        }
        if ($needle === '' || preg_match('/\b(this|that|the|diese|dieses|content)\b/iu', $needle) === 1) {
            // Fall back: a header on this page that appears in the request and matches more than once.
            foreach (self::defaultLanguageContentOnPage($openPageId) as $row) {
                $header = trim((string) ($row['header'] ?? ''));
                if ($header === '' || mb_stripos($requestQuery, $header) === false) {
                    continue;
                }
                $matches = self::contentUidsMatchingHeaderOnPage($openPageId, $header);
                if (count($matches) >= 2) {
                    $needle = $header;
                    break;
                }
            }
        }
        if ($needle === '') {
            return null;
        }
        $matches = self::contentUidsMatchingHeaderOnPage($openPageId, $needle);
        if (count($matches) < 2) {
            return null;
        }
        $options = [];
        foreach ($matches as $match) {
            $options[] = sprintf('%s [%d]', $match['header'] !== '' ? $match['header'] : 'Element', $match['uid']);
        }

        return [
            'role' => 'assistant',
            'content' => sprintf('Which content element called "%s" should I translate?', $needle),
            'meta' => [
                'type' => 'clarification',
                'tool' => 'ask_clarification',
                'options' => $options,
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
            ],
        ];
    }

    private static function soleDefaultLanguageContentUid(int $pageId): int
    {
        $rows = self::defaultLanguageContentOnPage($pageId);
        if (count($rows) !== 1) {
            return 0;
        }

        return (int) ($rows[0]['uid'] ?? 0);
    }

    /**
     * @return list<array{uid: int, header: string}>
     */
    private static function contentUidsMatchingHeaderOnPage(int $pageId, string $needle): array
    {
        $needle = mb_strtolower(trim($needle));
        $matches = [];
        foreach (self::defaultLanguageContentOnPage($pageId) as $row) {
            $header = trim((string) ($row['header'] ?? ''));
            if ($header !== '' && mb_strtolower($header) === $needle) {
                $matches[] = ['uid' => (int) $row['uid'], 'header' => $header];
            }
        }

        return $matches;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function defaultLanguageContentOnPage(int $pageId): array
    {
        try {
            $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
            $query->getRestrictions()->removeAll();
            $rows = $query
                ->select('uid', 'header')
                ->from('tt_content')
                ->where(
                    $query->expr()->eq('pid', $query->createNamedParameter($pageId, Connection::PARAM_INT)),
                    $query->expr()->eq('sys_language_uid', $query->createNamedParameter(0, Connection::PARAM_INT)),
                    $query->expr()->eq('deleted', $query->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('sorting', 'ASC')
                ->setMaxResults(50)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Add requests must not open a remove card. Null when the remove tool is allowed.
     */
    public static function refuseQueueRemoveOnAddRequest(string $toolName, string $requestQuery): ?string
    {
        $tool = strtolower(trim($toolName));
        if (!in_array($tool, ['t3ai_mass_seo_queue_remove', 't3ai_mass_translation_queue_remove'], true)) {
            return null;
        }
        if ($requestQuery === '') {
            return null;
        }
        $asksRemove = preg_match(
            '/\b(remove|delete|drop|cancel|take\b[^.?!]{0,40}\bout|out\s+of|get\s+rid|clear|entfern\w*|lösch\w*|stornier\w*|abbrechen|raus\w*|herausnehm\w*|aus\s+der)\b/iu',
            $requestQuery,
        ) === 1;
        if ($asksRemove) {
            return null;
        }
        $asksAdd = preg_match(
            '/\b(add|put|schedule|hinzu\w*|aufnehm\w*|einplan\w*)\b/iu',
            $requestQuery,
        ) === 1;
        if (!$asksAdd) {
            return null;
        }

        return $tool === 't3ai_mass_seo_queue_remove'
            ? 'You asked to add pages to the SEO queue, not remove them. If they are already queued, say so — do not remove them.'
            : 'You asked to add pages to the translation queue, not remove them. If they are already queued, say so — do not remove them.';
    }

    /**
     * "Retry the failed ones" means every failed row. A page id the model copied from the open page or
     * from a finished row would requeue nothing, so the list is dropped unless the editor gave numbers.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function requeueFailedOnly(array $arguments, string $requestQuery): array
    {
        if (!empty($arguments['includeFinished'])) {
            return $arguments;
        }
        if (
            preg_match('/\b(fail\w*|fehlgeschlag\w*|fehler\w*|errors?)\b/iu', $requestQuery) === 1
            && preg_match('/\d/', $requestQuery) !== 1
        ) {
            unset($arguments['pageIds'], $arguments['pageId'], $arguments['pid']);
            $arguments['includeFinished'] = false;
        }

        return $arguments;
    }

    /**
     * DeepL and Google Translate are not agent providers. When the editor asks for one, the card must
     * say which provider is really used instead of leaving the editor to assume.
     *
     * @param array<string, mixed> $arguments
     */
    private function translationProviderNotice(string $toolName, array $arguments, string $requestQuery): string
    {
        if (!self::isTranslateTool($toolName)) {
            return '';
        }
        if (preg_match('/\b(deepl|google\s*translate|google)\b/iu', $requestQuery, $match) !== 1) {
            return '';
        }
        $used = trim((string) ($arguments['aiProvider'] ?? ''));
        if ($used === '') {
            $used = $this->translator->translate('agent.translate.defaultProvider');
            if ($used === 'agent.translate.defaultProvider') {
                $used = 'your default AI provider';
            }
        }
        $label = $this->translator->translate('agent.translate.providerNotice', [$match[1], $used]);
        if ($label === 'agent.translate.providerNotice') {
            $label = sprintf(
                "%s can't be used in the agent, so this translation uses %s. For %s, use the page translation dialog.",
                $match[1],
                $used,
                $match[1],
            );
        }

        return "\n\n" . $label;
    }

    /**
     * The model sometimes sends the languages as {"languageUids":[1]} or {"languageUid":1}. The card and
     * the queue tool both want the plain list.
     */
    public static function unwrapLanguageConfig(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !in_array($trimmed[0], ['{', '['], true)) {
                return $value;
            }
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                return $value;
            }
            $value = $decoded;
        }
        if (!is_array($value) || array_is_list($value)) {
            return $value;
        }
        foreach (['languageUids', 'languageUid', 'languageIds', 'languageId', 'languages', 'language'] as $key) {
            if (array_key_exists($key, $value)) {
                $inner = $value[$key];

                return is_array($inner) ? $inner : [$inner];
            }
        }

        return $value;
    }

    public static function isTranslateTool(string $toolName): bool
    {
        return in_array(strtolower(trim($toolName)), ['t3ai_translate_content', 't3ai_translate_page', 't3ai_translate_news', 't3ai_record_translate'], true);
    }

    /**
     * The turn that ends a request for a language the site does not have, or null.
     *
     * @param array<string, mixed> $context
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function unavailableLanguageTurn(string $toolName, array $context, string $requestQuery, string $correlationId): ?array
    {
        if ($requestQuery === '' || !RequestedLanguage::isLanguageTool($toolName)) {
            return null;
        }
        $siteLanguages = GlossarySaveLanguage::siteLanguages($context);
        $missing = RequestedLanguage::unavailable($requestQuery, $siteLanguages);
        if ($missing === null) {
            return null;
        }
        $message = $this->translator->translate('agent.language.notAvailable', [$missing, RequestedLanguage::titles($siteLanguages)]);
        if ($message === 'agent.language.notAvailable') {
            $message = sprintf(
                "%s isn't a language of this site (available: %s). Nothing was changed. Pick one of the site languages.",
                $missing,
                RequestedLanguage::titles($siteLanguages),
            );
        }

        return [
            'role' => 'assistant',
            'content' => $message,
            'meta' => ['type' => 'error', 'tool' => $toolName, 'correlationId' => $correlationId, 'orchestratorPause' => true],
        ];
    }

    /**
     * @param list<string> $options
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function glossaryLanguageQuestion(array $options, string $correlationId): array
    {
        $question = $this->translator->translate('agent.glossary.whichLanguage');
        if ($question === '' || $question === 'agent.glossary.whichLanguage') {
            $question = 'Which language should I save this word in?';
        }

        return [
            'role' => 'assistant',
            'content' => $question,
            'meta' => [
                'type' => 'clarification',
                'tool' => 'ask_clarification',
                'options' => array_values($options),
                'correlationId' => $correlationId,
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
            // SEO/translation queue add: a model pageIds list of the open page must not beat the named page.
            if (in_array(strtolower(trim($toolName)), ['t3ai_mass_seo_queue_add', 't3ai_mass_translation_queue_add'], true)) {
                unset($arguments['pageIds']);
                $arguments['pageId'] = $lockedPageId;
            }
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
        $namesSeoQueuePages = in_array(strtolower(trim($toolName)), ['t3ai_mass_seo_queue_add', 't3ai_mass_seo_queue_remove'], true)
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

        // "Create a page here / under this page": the open page is the parent unless the model named another one.
        if (
            $pageId > 0
            && in_array(strtolower(trim($toolName)), self::PARENT_PAGE_TOOLS, true)
            && (int) ($arguments['parentPageId'] ?? 0) <= 0
            && trim((string) ($arguments['parentPageUrl'] ?? '')) === ''
        ) {
            $arguments['parentPageId'] = $pageId;
        }

        // The SEO queue add does not read pid. The open page must not appear as "Pid" on the card.
        if (strtolower(trim($toolName)) === 't3ai_mass_seo_queue_add') {
            unset($arguments['pid']);
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

        // The remove tool only reads pageIds. "This page" is the open page when no list was sent.
        if (in_array(strtolower(trim($toolName)), ['t3ai_mass_seo_queue_remove', 't3ai_mass_translation_queue_remove'], true)) {
            if (!self::hasExplicitPageIdList($arguments['pageIds'] ?? null)) {
                $removePageId = (int) ($arguments['pageId'] ?? 0);
                if ($removePageId > 0) {
                    $arguments['pageIds'] = [$removePageId];
                }
            }
            unset($arguments['pageId'], $arguments['pid']);
        }

        return $arguments;
    }

    /**
     * "Remove this page from the queue" means the open page. A model may pick another row from the
     * list, so the open page replaces its choice when the request names no page number.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function pinOpenPageForQueueRemove(array $arguments, int $openPageId, string $toolName, string $requestQuery): array
    {
        if ($openPageId <= 0 || !in_array(strtolower(trim($toolName)), ['t3ai_mass_seo_queue_remove', 't3ai_mass_translation_queue_remove'], true)) {
            return $arguments;
        }
        if (preg_match('/\d/', $requestQuery) === 1) {
            return $arguments;
        }
        if (preg_match('/\b(this|current|open)\s+page\b|\b(diese|aktuelle)\s+seite\b/iu', $requestQuery) !== 1) {
            return $arguments;
        }
        $arguments['pageIds'] = [$openPageId];

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
    private function enrichPlannedToolArguments(string $toolName, array $arguments, array $history, string $request = ''): array
    {
        $toolName = strtolower(trim($toolName));
        if ($toolName === 't3ai_generate_image') {
            unset($arguments['pageId'], $arguments['pid']);
            $newsUid = AgentPromptBuilder::latestAppliedNewsUid($history);
            if ($newsUid !== null) {
                $arguments['newsArticleUid'] = $newsUid;
            }
            // Smaller models send count 1 for "make 3 pictures".
            $count = AgentRequestedFiles::requestedImageCount($request);
            if ($count > 1 && (int) ($arguments['count'] ?? 1) <= 1) {
                $arguments['count'] = $count;
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

        $pending = AgentPromptBuilder::pendingAttachFileUids($history, $request);
        $fileUidsRaw = trim((string) ($arguments['fileUids'] ?? ''));
        if ($fileUidsRaw !== '' && !self::fileUidsArgumentIsNumericList($fileUidsRaw)) {
            $resolved = $pending;
            if ($resolved === []) {
                throw new \InvalidArgumentException(
                    'No generated file is available yet. Call t3ai_generate_image first and wait until the editor applies it, then attach using the numeric sys_file uid from that result.',
                );
            }
            $arguments['fileUids'] = implode(',', array_map(static fn(int $uid): string => (string) $uid, $resolved));
            $fileUidsRaw = (string) $arguments['fileUids'];
        }

        if ($pending === []) {
            throw new \InvalidArgumentException(
                'There is no file left to attach from this conversation (uploads, generated images, or named sys_file uids). Do not claim the image is already attached — call file_reference_list if you need to check.',
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
        if (strtolower($toolName) === 't3ai_mass_translation_queue_add' && isset($arguments['languageConfig'])) {
            $arguments['languageConfig'] = self::unwrapLanguageConfig($arguments['languageConfig']);
        }
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
