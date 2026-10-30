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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileAccess;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
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

    /**
     * A missing id is asked as buttons. The value is the argument the button fills.
     *
     * @var array<string, string>
     */
    private const CHOICE_ARGUMENT = [
        't3cs_list_queue_items' => 'dataId',
        't3cs_reset_failed_queue_item' => 'dataId',
        't3cs_sync_datasource' => 'dataId',
        't3cs_save_datasource' => 'dataId',
        't3ac_chatbot_settings' => 'chatbotUid',
        't3ac_chatbot_settings_get' => 'chatbotUid',
    ];

    /** @var array<string, string> */
    private const SOURCE_QUESTION = [
        't3cs_list_queue_items' => 'agent.queue.whichSource',
        't3cs_reset_failed_queue_item' => 'agent.source.whichReset',
        't3cs_sync_datasource' => 'agent.source.whichSync',
        't3cs_save_datasource' => 'agent.source.whichUpdate',
    ];

    /** Site root is filled only when the tool already takes rootPageId and the caller left it empty. */
    private const ROOT_PAGE_TOOLS = [
        't3cs_list_datasources',
        't3cs_training_summary',
        't3cs_usage_analytics_summary',
        't3cs_sync_datasource',
        't3cs_save_datasource',
        't3as_search_settings',
        't3as_search_settings_get',
        't3as_list_predefined_questions',
        't3as_predefined_questions_create',
    ];

    /**
     * These tables are not versioned. A workspace id on the card does not change the stored row.
     * Versioned tools, including t3as_predefined_questions_create, keep the editor's workspace.
     */
    private const WORKSPACE_EXEMPT_TOOLS = [
        't3cs_list_datasources',
        't3cs_training_summary',
        't3cs_usage_analytics_summary',
        't3cs_sync_datasource',
        't3cs_save_datasource',
        't3as_search_settings',
        't3as_search_settings_get',
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
        if ($toolName === 't3aa_get_file_metadata') {
            $history = is_array($body['conversationHistory'] ?? null) ? array_values($body['conversationHistory']) : [];
            $arguments = self::withChosenFile($arguments, self::openQuestionMeta($history), trim((string) ($body['userMessage'] ?? '')));
            // A number in the named file ("…_629764.jpeg") is not its uid.
            $arguments = self::withRequestedFileName(
                $arguments,
                trim((string) ($body['requestQuery'] ?? '')),
                null,
                $history,
            );
            $body['arguments'] = $arguments;
            $fileChoice = $this->fileChoiceTurn($arguments, $toolName, $correlationId);
            if ($fileChoice !== null) {
                return $fileChoice;
            }
        }
        $reroutedTool = $this->rerouteWriteTableTool($toolName, $arguments, $catalog);
        if ($reroutedTool !== null) {
            $body['arguments'] = $reroutedTool['arguments'];

            return $this->execute($reroutedTool['tool'], $context, $body, $user, $correlationId);
        }

        $rawTool = $this->findRawTool($toolName);
        if ($rawTool !== null && $this->permittedActionProvider->isHiddenFromAgent($rawTool)) {
            $this->auditLogger->logToolInvocation($correlationId, $toolName, $arguments, false, 0, 'not_available_in_agent');
            $hiddenMessage = $toolName === 't3aa_get_file_for_metadata'
                ? 't3aa_get_file_for_metadata is not available in the AI Agent. Call t3aa_get_file_metadata with the same fileUid; it returns public_url. Do not invent a URL.'
                : $this->translator->translate('agent.turn.notAvailableInAgent', [$toolName]);

            return [
                'role' => 'assistant',
                'content' => $hiddenMessage,
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

        $choiceTurn = $this->choiceTurnBeforeRun($toolName, $body, $context, $catalog, $correlationId);
        if ($choiceTurn !== null) {
            return $choiceTurn;
        }

        $searchRead = $this->searchSettingsReadTool($toolName, $body, $catalog);
        if ($searchRead !== null) {
            $tool = $searchRead;
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
        $meta = $this->withPresentedLines($meta, $presented);

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
        $meta = $this->withPresentedLines($meta, $presented);

        return [
            'role' => 'assistant',
            'content' => (string) $presented['content'],
            'meta' => $meta,
        ];
    }

    /**
     * A disabled feature already tells the editor what to turn on. The preview-failed
     * wrapper would hide that behind “try again”.
     */
    private static function isFeatureDisabledMessage(string $message): bool
    {
        $lower = mb_strtolower(trim($message));

        return $lower !== '' && (str_contains($lower, 'turned off') || str_contains($lower, 'currently disabled'));
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
        if ($toolName === 't3aa_update_file_metadata') {
            $fileMetadata = $this->prepareFileMetadataTurn($arguments, $body, $requestQuery, $correlationId);
            if ($fileMetadata['turn'] !== null) {
                return $fileMetadata['turn'];
            }
            $arguments = $fileMetadata['arguments'];
        }
        $arguments = self::seoBatchSubpagesOfOpenPage($arguments, $toolName, $requestQuery);
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
            $requestQuery,
        );
        $variants = max(1, min(5, (int) ($arguments['variants'] ?? 3)));
        unset($arguments['variants']);

        $result = $this->playgroundService->preview($toolName, $arguments, $variants);
        // A preview-only switch: saving writes exactly the fields on the card.
        unset($arguments['onlyFields']);
        if (($result['success'] ?? false) !== true || !$result['preview'] instanceof PreviewResult) {
            $failureDetail = AgentPermissionMessage::rewrite(
                (string) ($result['message'] ?? ''),
                $this->translator,
            );
            $this->auditLogger->logToolInvocation($correlationId, $toolName, $arguments, false, 0, mb_substr((string) ($result['message'] ?? ''), 0, 250));

            $content = self::isFeatureDisabledMessage($failureDetail)
                ? $failureDetail
                : $this->translator->translate(
                    'agent.turn.previewFailed',
                    [$this->editorLabelService->resolve($tool) ?: $toolName, $failureDetail],
                );

            return [
                'role' => 'assistant',
                'content' => $content,
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
     * Before image texts are generated: ask which file when several share the requested name, and ask
     * before replacing texts the image already has. The editor's click on an option answers the question.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $body
     * @return array{arguments: array<string, mixed>, turn: array{role: string, content: string, meta: array<string, mixed>}|null}
     */
    private function prepareFileMetadataTurn(array $arguments, array $body, string $requestQuery, string $correlationId): array
    {
        $history = is_array($body['conversationHistory'] ?? null) ? array_values($body['conversationHistory']) : [];
        $reply = trim((string) ($body['userMessage'] ?? ''));
        $question = self::openQuestionMeta($history);
        $requestQuery = self::namedImageTextRequest($requestQuery, $history);

        $arguments = self::withChosenFile($arguments, $question, $reply);
        $arguments = self::withRequestedFileName($arguments, $requestQuery, null, $history);
        $fileChoice = $this->fileChoiceTurn($arguments, 't3aa_update_file_metadata', $correlationId);
        if ($fileChoice !== null) {
            return ['arguments' => $arguments, 'turn' => $fileChoice];
        }

        $givenByEditor = false;
        foreach (['altText', 'title', 'description'] as $key) {
            $value = trim((string) ($arguments[$key] ?? ''), " \t\n\r\0\x0B\"'“”„«»");
            // Text the model wrote itself is not used: the tool generates it from the image instead.
            if (
                $value === ''
                || !self::writtenInRequest($value, $requestQuery)
                // The file name itself ("photo.jpg") is not a text for the file.
                || preg_match('/^\S+\.[a-z0-9]{2,5}$/iu', $value) === 1
            ) {
                unset($arguments[$key]);
                continue;
            }
            $arguments[$key] = $value;
            $givenByEditor = true;
        }
        if ($givenByEditor) {
            // The card shows the editor's text next to the current one.
            return ['arguments' => $arguments, 'turn' => null];
        }

        $current = $this->currentFileMetadata($arguments);
        if ($current === null) {
            return ['arguments' => $arguments, 'turn' => null];
        }
        $filled = array_keys(array_filter($current['fields'], static fn(string $value): bool => trim($value) !== ''));
        if ($filled === []) {
            return ['arguments' => $arguments, 'turn' => null];
        }

        $asked = is_array($question['fileMetaOverwrite'] ?? null) ? $question['fileMetaOverwrite'] : [];
        if ((int) ($asked['fileUid'] ?? 0) === $current['fileUid'] && (int) ($asked['languageUid'] ?? -1) === $current['languageUid']) {
            $choices = is_array($asked['choices'] ?? null) ? $asked['choices'] : [];
            if (self::sameChoice($reply, (string) ($choices['keep'] ?? ''))) {
                $doneHistory = array_merge($history, [[
                    'role' => 'assistant',
                    'content' => '',
                    'meta' => [
                        'type' => 'suggestions',
                        'tool' => 't3aa_update_file_metadata',
                        'previews' => [['fileUid' => $current['fileUid'], 'name' => $current['fileName']]],
                    ],
                ]]);
                $nextNamed = self::nextRequestedFileName($requestQuery, $doneHistory);
                $nextMissing = self::nextMissingAltFile($doneHistory, $requestQuery);
                // The overwrite card is ask_clarification, so it does not mark the basename done.
                // Recurse with the synthetic suggestions or withRequestedFileName retargets file 1.
                $body['conversationHistory'] = $doneHistory;
                if ($nextNamed !== null) {
                    return $this->prepareFileMetadataTurn(['fileUrl' => $nextNamed], $body, $requestQuery, $correlationId);
                }
                if ($nextMissing !== null) {
                    return $this->prepareFileMetadataTurn(['fileUid' => $nextMissing['fileUid']], $body, $requestQuery, $correlationId);
                }

                return ['arguments' => $arguments, 'turn' => [
                    'role' => 'assistant',
                    'content' => $this->translator->translate('agent.fileMeta.kept', [$current['fileName']]),
                    'meta' => ['type' => 'nl_reply', 'correlationId' => $correlationId, 'orchestratorPause' => true],
                ]];
            }
            if (self::sameChoice($reply, (string) ($choices['fillEmpty'] ?? ''))) {
                $arguments['onlyFields'] = array_values(array_diff(array_keys($current['fields']), $filled));
            }

            // "Regenerate", or an answer in the editor's own words after which the model still prepares the change.
            return ['arguments' => $arguments, 'turn' => null];
        }

        $choices = ['regenerate' => $this->translator->translate('agent.fileMeta.option.regenerate')];
        if (count($filled) < count($current['fields'])) {
            $choices['fillEmpty'] = $this->translator->translate('agent.fileMeta.option.fillEmpty');
        }
        $choices['keep'] = $this->translator->translate('agent.fileMeta.option.keep');

        $lines = [];
        foreach ($current['fields'] as $key => $value) {
            $value = trim($value);
            $lines[] = sprintf(
                '- %s: %s',
                $this->translator->translate('agent.fileMeta.field.' . $key),
                $value !== '' ? mb_strimwidth($value, 0, 160, '…') : $this->translator->translate('agent.fileMeta.field.empty'),
            );
        }
        $languageLabel = self::languageTitle($current['languageUid']);

        return ['arguments' => $arguments, 'turn' => [
            'role' => 'assistant',
            'content' => $this->translator->translate('agent.fileMeta.overwriteQuestion', [
                $current['fileName'],
                $languageLabel !== '' ? ' (' . $languageLabel . ')' : '',
                implode("\n", $lines),
            ]),
            'meta' => [
                'type' => 'clarification',
                'tool' => 'ask_clarification',
                'options' => array_values($choices),
                'contentChoice' => true,
                'askedFor' => 't3aa_update_file_metadata',
                'fileMetaOverwrite' => [
                    'fileUid' => $current['fileUid'],
                    'languageUid' => $current['languageUid'],
                    'fileName' => $current['fileName'],
                    'choices' => $choices,
                ],
                'previews' => isset($this->mediaPreviews) ? $this->mediaPreviews->forRecord('sys_file', $current['fileUid']) : [],
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
            ],
        ]];
    }

    /**
     * Ask which datasource or chatbot to use when that id is missing. A call that already has the id
     * is left on its current path. Returns a question, or null after filling the only match onto $body.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function choiceTurnBeforeRun(string $toolName, array &$body, array $context, array $catalog, string $correlationId): ?array
    {
        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $requestText = trim((string) ($body['userMessage'] ?? '')) . ' ' . trim((string) ($body['requestQuery'] ?? ''));
        if (self::asksToDeleteDataSources($requestText)) {
            return $this->cannotDeleteDataSourcesTurn($correlationId);
        }
        if ($toolName === 't3cs_save_datasource') {
            $adjusted = $this->applyDatasourceCreateAdjustment($body, $requestText, $correlationId);
            if ($adjusted !== null) {
                return $adjusted;
            }
        }
        $detour = $this->chatbotSettingsDetour($toolName, $body, $catalog, $correlationId);
        if ($detour !== null) {
            return $detour;
        }
        $unsaved = $this->unsavedSourceFollowUp($toolName, $arguments, $body, $context, $correlationId);
        if ($unsaved !== null) {
            return $unsaved;
        }
        if ($toolName === 't3cs_sync_datasource') {
            $named = $this->resolveNamedSync($arguments, $body, $context, $correlationId);
            if ($named !== null) {
                return $named;
            }
            $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : $arguments;
        }

        $sourceQuestion = self::SOURCE_QUESTION[$toolName] ?? null;
        if ($sourceQuestion !== null && $this->sourceIdIsMissing($toolName, $arguments)) {
            $resolved = $this->resolveMissingQueueSource($context, $correlationId, $sourceQuestion, $toolName, $arguments);
            if ($resolved['turn'] !== null) {
                return $resolved['turn'];
            }
            $arguments['dataId'] = $resolved['dataId'];
            if ($toolName === 't3cs_sync_datasource') {
                $arguments['scope'] = 'one';
            }
            if ($toolName === 't3cs_save_datasource' && trim((string) ($arguments['operation'] ?? '')) === '') {
                $arguments['operation'] = 'update';
            }
            $body['arguments'] = $arguments;

            return null;
        }

        if (isset(self::CHOICE_ARGUMENT[$toolName]) && self::CHOICE_ARGUMENT[$toolName] === 'chatbotUid' && (int) ($arguments['chatbotUid'] ?? 0) <= 0) {
            $resolved = $this->resolveMissingChatbot($context, $correlationId, $toolName, $arguments);
            if ($resolved['turn'] !== null) {
                return $resolved['turn'];
            }
            $arguments['chatbotUid'] = $resolved['chatbotUid'];
            $body['arguments'] = $arguments;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function sourceIdIsMissing(string $toolName, array $arguments): bool
    {
        if ((int) ($arguments['dataId'] ?? 0) > 0) {
            return false;
        }
        if ($toolName === 't3cs_sync_datasource' && strtolower(trim((string) ($arguments['scope'] ?? 'one'))) === 'all') {
            return false;
        }
        if ($toolName === 't3cs_save_datasource' && strtolower(trim((string) ($arguments['operation'] ?? 'create'))) !== 'update') {
            return false;
        }

        return true;
    }

    /**
     * The editor pointed at a source that was never saved ("sync that one" after a failed create).
     * A named, existing source is not touched.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function unsavedSourceFollowUp(string $toolName, array $arguments, array $body, array $context, string $correlationId): ?array
    {
        if (!in_array($toolName, ['t3cs_sync_datasource', 't3cs_save_datasource'], true)) {
            return null;
        }
        $operation = strtolower(trim((string) ($arguments['operation'] ?? '')));
        if ($toolName === 't3cs_save_datasource' && $operation !== 'update') {
            return null;
        }
        $history = is_array($body['conversationHistory'] ?? null) ? $body['conversationHistory'] : [];
        $text = trim((string) ($body['userMessage'] ?? '')) . ' ' . trim((string) ($body['requestQuery'] ?? ''));
        $pronoun = self::refersToThatSource($text);
        $failedCreate = self::latestSaveFailed($history);
        $scopeAll = $toolName === 't3cs_sync_datasource' && strtolower(trim((string) ($arguments['scope'] ?? 'one'))) === 'all';
        $dataId = (int) ($arguments['dataId'] ?? 0);

        if ($failedCreate && ($pronoun || ($scopeAll && $pronoun))) {
            return $this->queueNotice($this->translator->translate('agent.source.notCreated'), $correlationId);
        }
        // Sync scope and a named source are resolved next. An unknown id is checked there,
        // so a quoted name can replace an invented id instead of stopping at "not found".
        if ($toolName === 't3cs_sync_datasource' || $scopeAll) {
            return null;
        }

        return $dataId > 0 ? $this->unknownSourceTurn($dataId, $context, $correlationId, $failedCreate && $pronoun) : null;
    }

    /**
     * "Only the sitemap" stays scope one. Two sources with the same name are a question.
     * An id that is not on this site is not drafted. Scope all stays only when the editor asked for every source.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function resolveNamedSync(array $arguments, array &$body, array $context, string $correlationId): ?array
    {
        $sources = $this->datasourceRows($context);
        if ($sources === null) {
            return null;
        }
        $text = trim((string) ($body['userMessage'] ?? '')) . ' ' . trim((string) ($body['requestQuery'] ?? ''));
        $decision = self::namedSyncDecision(
            $text,
            $sources,
            (string) ($arguments['scope'] ?? 'one'),
            (int) ($arguments['dataId'] ?? 0),
        );
        if ($decision['action'] === 'use' || ($decision['action'] === 'keep' && $decision['scope'] === 'all')) {
            $arguments['scope'] = $decision['scope'];
            $arguments['dataId'] = $decision['dataId'];
            $body['arguments'] = $arguments;

            return null;
        }
        if ($decision['action'] === 'ask' && $decision['options'] !== []) {
            $pending = $arguments;
            $pending['scope'] = 'one';
            unset($pending['dataId']);

            return [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.source.whichSync'),
                'meta' => [
                    'type' => 'clarification',
                    'tool' => 'ask_clarification',
                    'options' => $decision['options'],
                    'contentChoice' => true,
                    'askedFor' => 't3cs_sync_datasource',
                    'pendingArguments' => $pending,
                    'correlationId' => $correlationId,
                    'orchestratorPause' => true,
                ],
            ];
        }
        if ($decision['action'] === 'notFound') {
            return $this->queueNotice(
                $this->translator->translate('agent.source.notFound', [$decision['dataId']]),
                $correlationId,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>|null null when the list could not be read
     */
    private function datasourceRows(array $context): ?array
    {
        $rootPageId = $this->listingRootPageId($context);
        if ($rootPageId <= 0) {
            return null;
        }
        $payload = $this->invokePayload('t3cs_list_datasources', ['rootPageId' => $rootPageId]);
        if ($payload === null || (isset($payload['error']) && is_string($payload['error']))) {
            return null;
        }
        $sources = is_array($payload['datasources'] ?? null) ? $payload['datasources'] : [];
        $rows = [];
        foreach ($sources as $source) {
            if (is_array($source) && (int) ($source['uid'] ?? 0) > 0) {
                $rows[] = $source;
            }
        }

        return $rows;
    }

    /**
     * A positive id that is not one of this site's sources does not become a draft.
     * When the source list cannot be read, the existing path stays.
     *
     * @param array<string, mixed> $context
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function unknownSourceTurn(int $dataId, array $context, string $correlationId, bool $afterFailedCreate): ?array
    {
        if ($dataId <= 0) {
            return null;
        }
        $known = $this->datasourceIds($context);
        if ($known === null || in_array($dataId, $known, true)) {
            return null;
        }
        $message = $afterFailedCreate
            ? $this->translator->translate('agent.source.notCreated')
            : $this->translator->translate('agent.source.notFound', [$dataId]);

        return $this->queueNotice($message, $correlationId);
    }

    /**
     * @param array<string, mixed> $context
     * @return list<int>|null null when the list could not be read
     */
    private function datasourceIds(array $context): ?array
    {
        $rootPageId = $this->listingRootPageId($context);
        if ($rootPageId <= 0) {
            return null;
        }
        $payload = $this->invokePayload('t3cs_list_datasources', ['rootPageId' => $rootPageId]);
        if ($payload === null || (isset($payload['error']) && is_string($payload['error']))) {
            return null;
        }
        $sources = is_array($payload['datasources'] ?? null) ? $payload['datasources'] : [];
        $ids = [];
        foreach ($sources as $source) {
            if (is_array($source) && (int) ($source['uid'] ?? 0) > 0) {
                $ids[] = (int) $source['uid'];
            }
        }

        return $ids;
    }

    /**
     * Chatbot settings are not data sources or AI Search settings.
     * A locked chatbot tool answers with its own message, whichever tool was called.
     *
     * @param array<string, mixed> $body
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function chatbotSettingsDetour(string $toolName, array $body, array $catalog, string $correlationId): ?array
    {
        $text = trim((string) ($body['userMessage'] ?? '')) . ' ' . trim((string) ($body['requestQuery'] ?? ''));
        $locked = null;
        foreach ($catalog['locked'] as $tool) {
            if ((string) ($tool['name'] ?? '') === 't3ac_chatbot_settings_get') {
                $locked = $tool;
                break;
            }
        }
        if ($locked === null || !self::chatbotSettingsNeedLicenceMessage($text, true)) {
            return null;
        }

        return [
            'role' => 'assistant',
            'content' => $this->entitlementExplanation->buildMessage($locked),
            'meta' => array_merge(
                $this->entitlementExplanation->buildMeta($locked),
                [
                    'correlationId' => $correlationId,
                    'orchestratorPause' => true,
                    'replacedTool' => $toolName,
                ],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $pendingArguments
     * @return array{turn: array{role: string, content: string, meta: array<string, mixed>}|null, chatbotUid: int}
     */
    private function resolveMissingChatbot(array $context, string $correlationId, string $askedFor, array $pendingArguments): array
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $payload = $this->invokePayload('t3ac_chatbot_list', $pageId > 0 ? ['pageId' => $pageId] : []);
        if ($payload === null) {
            return ['turn' => $this->queueNotice($this->translator->translate('agent.chatbot.none'), $correlationId), 'chatbotUid' => 0];
        }
        if (isset($payload['error']) && is_string($payload['error']) && trim($payload['error']) !== '') {
            return ['turn' => $this->queueNotice(trim($payload['error']), $correlationId), 'chatbotUid' => 0];
        }
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $decision = self::chatbotDecision($items);
        if ($decision['chatbotUid'] > 0) {
            return ['turn' => null, 'chatbotUid' => $decision['chatbotUid']];
        }
        if ($decision['options'] === []) {
            return ['turn' => $this->queueNotice($this->translator->translate('agent.chatbot.none'), $correlationId), 'chatbotUid' => 0];
        }

        return [
            'turn' => [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.chatbot.whichOne'),
                'meta' => [
                    'type' => 'clarification',
                    'tool' => 'ask_clarification',
                    'options' => $decision['options'],
                    'contentChoice' => true,
                    'askedFor' => $askedFor,
                    'pendingArguments' => $pendingArguments,
                    'correlationId' => $correlationId,
                    'orchestratorPause' => true,
                ],
            ],
            'chatbotUid' => 0,
        ];
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function cannotDeleteDataSourcesTurn(string $correlationId): array
    {
        $sentence = $this->translator->translate('agent.datasource.cannotDelete');
        if ($sentence === '' || $sentence === 'agent.datasource.cannotDelete') {
            $sentence = 'The agent cannot delete data sources. You can still list them. Remove a data source in the AI Chatbot/Search module, on the Data Sources tab.';
        }

        return [
            'role' => 'assistant',
            'content' => $sentence,
            'meta' => [
                'type' => 'info',
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invokePayload(string $toolName, array $arguments): ?array
    {
        $listed = $this->playgroundService->invoke($toolName, $arguments);
        $payload = $listed['result'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (($listed['success'] ?? false) !== true || !is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * One chatbot on this site is used at once. Several on this site become buttons.
     * When none are on this site, every chatbot is offered.
     *
     * @param list<array<string, mixed>> $items
     * @return array{chatbotUid: int, options: list<string>}
     */
    public static function chatbotDecision(array $items): array
    {
        $onSite = [];
        $all = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uid = (int) ($item['chatbotUid'] ?? $item['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $label = ($title !== '' ? $title : 'Chatbot') . ' [' . $uid . ']';
            $all[] = $label;
            if (($item['onThisSite'] ?? false) === true) {
                $onSite[] = ['uid' => $uid, 'label' => $label];
            }
        }
        if (count($onSite) === 1) {
            return ['chatbotUid' => $onSite[0]['uid'], 'options' => []];
        }
        if (count($onSite) > 1) {
            return ['chatbotUid' => 0, 'options' => array_column($onSite, 'label')];
        }
        if (count($all) === 1) {
            return ['chatbotUid' => self::uidInBrackets($all[0]), 'options' => []];
        }

        return ['chatbotUid' => 0, 'options' => $all];
    }

    /**
     * The button the editor clicked, including the arguments that were already known.
     *
     * @param list<array<string, mixed>> $history
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    public static function openChoiceTarget(string $reply, array $history): ?array
    {
        $question = self::openQuestionMeta($history);
        $tool = (string) ($question['askedFor'] ?? '');
        $argument = self::CHOICE_ARGUMENT[$tool] ?? null;
        if ($argument === null) {
            return null;
        }
        $options = array_values(array_filter(
            is_array($question['options'] ?? null) ? $question['options'] : [],
            'is_string',
        ));
        $uid = self::uidFromChoiceOptions(trim($reply), $options);
        if ($uid <= 0) {
            return null;
        }
        $arguments = is_array($question['pendingArguments'] ?? null) ? $question['pendingArguments'] : [];
        $arguments[$argument] = $uid;
        if ($tool === 't3cs_sync_datasource') {
            $arguments['scope'] = 'one';
        }
        if ($tool === 't3cs_save_datasource' && trim((string) ($arguments['operation'] ?? '')) === '') {
            $arguments['operation'] = 'update';
        }

        return ['tool' => $tool, 'arguments' => $arguments];
    }

    /**
     * @param list<string> $options
     */
    private static function uidFromChoiceOptions(string $reply, array $options): int
    {
        foreach ($options as $option) {
            if (self::sameChoice($reply, $option)) {
                return self::uidInBrackets($option);
            }
        }
        $uid = 0;
        if (preg_match('/\[(\d+)\]\s*$/', $reply, $bracket) === 1) {
            $uid = (int) $bracket[1];
        } elseif (preg_match('/^\d+$/', $reply) === 1) {
            $uid = (int) $reply;
        }
        if ($uid <= 0) {
            return 0;
        }
        foreach ($options as $option) {
            if (self::uidInBrackets($option) === $uid) {
                return $uid;
            }
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    public static function latestSaveFailed(array $history): bool
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $entry = $history[$i];
            if (!is_array($entry)) {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if ((string) ($meta['tool'] ?? '') !== 't3cs_save_datasource') {
                continue;
            }
            if (($meta['success'] ?? true) === false || ($meta['type'] ?? '') === 'error') {
                return true;
            }

            return false;
        }

        return false;
    }

    public static function refersToThatSource(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach (['that one', 'that source', 'this one', 'this source', 'activate it', 'sync it', 'sync that', 'diese eine', 'diese quelle', 'die eine'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A URL request must not become an invented Q&A source. A named TYPO3 page must not either,
     * unless the editor asked for a Q&A or FAQ source.
     *
     * @param array<string, mixed> $arguments
     * @return array{action: string, url?: string, pageId?: int}
     */
    public static function datasourceCreateAdjustment(string $text, array $arguments): array
    {
        $operation = strtolower(trim((string) ($arguments['operation'] ?? 'create')));
        if ($operation !== '' && $operation !== 'create') {
            return ['action' => 'keep'];
        }
        if (self::asksForQaSource($text)) {
            return ['action' => 'keep'];
        }
        $type = strtolower(trim((string) ($arguments['type'] ?? '')));
        if (self::invalidUrlNamedInText($text)) {
            return ['action' => 'invalid_url'];
        }
        $typeValue = trim((string) ($arguments['typeValue'] ?? $arguments['type_value'] ?? ''));
        if ($type === 'web_pages' && $typeValue !== '' && !self::looksLikeWebAddress($typeValue)) {
            return ['action' => 'invalid_url'];
        }
        $url = self::httpUrlInText($text);
        if ($url !== null && $type === 'qa') {
            return ['action' => 'web_pages', 'url' => $url];
        }
        if ($type === 'qa' && self::asksToTrainAPage($text)) {
            $pageId = self::contentPageIdInText($text);
            if ($pageId > 0) {
                return ['action' => 'typo3_pages', 'pageId' => $pageId];
            }

            return ['action' => 'ask_page'];
        }

        return ['action' => 'keep'];
    }

    public static function asksForQaSource(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach (['q&a', 'q & a', 'faq', 'question and answer', 'questions and answers', 'fragen und antworten'] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    public static function invalidUrlNamedInText(string $text): bool
    {
        if (preg_match_all('/\burl\s*[:=]?\s*([^\s,;]+)/iu', $text, $matches) < 1) {
            return false;
        }
        foreach ($matches[1] as $token) {
            $token = trim((string) $token, " \t\n\r\0\x0B.\"'");
            if ($token === '') {
                continue;
            }
            $lower = mb_strtolower($token);
            if (!str_starts_with($lower, 'http://') && !str_starts_with($lower, 'https://')) {
                return true;
            }
        }

        return false;
    }

    public static function httpUrlInText(string $text): ?string
    {
        if (preg_match('#https?://[^\s,;\'"<>]+#i', $text, $match) !== 1) {
            return null;
        }

        return rtrim($match[0], '.');
    }

    public static function asksToTrainAPage(string $text): bool
    {
        $text = mb_strtolower($text);
        if (self::asksForQaSource($text) || self::httpUrlInText($text) !== null) {
            return false;
        }
        if (str_contains($text, 'web page') || str_contains($text, 'webseite')) {
            return false;
        }
        $aboutPage = str_contains($text, 'page') || str_contains($text, 'seite');
        $aboutTraining = str_contains($text, 'chatbot') || str_contains($text, 'search') || str_contains($text, 'suche');

        return $aboutPage && $aboutTraining;
    }

    public static function contentPageIdInText(string $text): int
    {
        if (preg_match_all('/\b((?:root\s+)?)(page|seite)\s+(?:id\s+)?(\d+)\b/iu', $text, $matches, PREG_SET_ORDER) < 1) {
            return 0;
        }
        $pageId = 0;
        foreach ($matches as $match) {
            if (trim((string) ($match[1] ?? '')) !== '') {
                continue;
            }
            $pageId = (int) ($match[3] ?? 0);
        }

        return $pageId;
    }

    public static function looksLikeWebAddress(string $value): bool
    {
        $value = trim($value);
        if (str_starts_with($value, 'singlePage:1|')) {
            $value = trim(substr($value, 13));
        }
        if (str_contains($value, '*')) {
            $value = trim(explode('*', $value, 2)[0] ?? '');
            if ($value !== '' && !str_ends_with($value, '/')) {
                $value .= '/';
            }
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https';
    }

    /**
     * @param array<string, mixed> $body
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function applyDatasourceCreateAdjustment(array &$body, string $requestText, string $correlationId): ?array
    {
        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $adjustment = self::datasourceCreateAdjustment($requestText, $arguments);
        if ($adjustment['action'] === 'invalid_url') {
            return $this->datasourceSentenceTurn($correlationId, 'agent.datasource.invalidUrl', 'That address is not a valid http or https URL. No data source was created.');
        }
        if ($adjustment['action'] === 'ask_page') {
            return $this->datasourceSentenceTurn($correlationId, 'agent.datasource.whichPage', 'Name the page the chatbot and search should learn. It will be added as a TYPO3 pages data source.');
        }
        if ($adjustment['action'] === 'web_pages') {
            $url = (string) ($adjustment['url'] ?? '');
            $arguments['type'] = 'web_pages';
            $arguments['typeValue'] = $url;
            unset($arguments['qaPairs'], $arguments['qa_pairs']);
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && $path !== '/') {
                $arguments['singlePage'] = 1;
            }
            $body['arguments'] = $arguments;
        }
        if ($adjustment['action'] === 'typo3_pages') {
            $arguments['type'] = 'typo3_pages';
            $arguments['typeValue'] = (string) ($adjustment['pageId'] ?? 0);
            unset($arguments['qaPairs'], $arguments['qa_pairs']);
            $body['arguments'] = $arguments;
        }

        return null;
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function datasourceSentenceTurn(string $correlationId, string $label, string $fallback): array
    {
        $sentence = $this->translator->translate($label);
        if ($sentence === '' || $sentence === $label) {
            $sentence = $fallback;
        }

        return [
            'role' => 'assistant',
            'content' => $sentence,
            'meta' => [
                'type' => 'info',
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
            ],
        ];
    }

    public static function asksForChatbotSettings(string $text): bool
    {
        $text = mb_strtolower($text);
        if (str_contains($text, 'data source') || str_contains($text, 'datasource') || str_contains($text, 'datenquelle')) {
            return false;
        }

        $aboutChatbot = str_contains($text, 'chatbot');
        $renamesChatbot = $aboutChatbot && (
            str_contains($text, 'rename')
            || str_contains($text, 'umbenennen')
            || str_contains($text, 'title')
            || str_contains($text, 'titel')
        );

        return $renamesChatbot
            || str_contains($text, 'chatbot settings')
            || str_contains($text, 'chatbot configuration')
            || str_contains($text, 'chatbot-einstellungen')
            || str_contains($text, 'chatbot einstellungen')
            || str_contains($text, 'einstellungen des chatbots')
            || str_contains($text, 'einstellungen für den chatbot');
    }

    /**
     * There is no data-source delete tool. A delete request must not be told it is a permission problem.
     */
    public static function asksToDeleteDataSources(string $text): bool
    {
        $text = mb_strtolower($text);
        $aboutSource = str_contains($text, 'data source')
            || str_contains($text, 'datasource')
            || str_contains($text, 'datenquelle');
        if (!$aboutSource || str_contains($text, 'queue') || str_contains($text, 'failed')) {
            return false;
        }

        foreach (['delete', 'remove', 'löschen', 'loeschen', 'entfern'] as $verb) {
            if (str_contains($text, $verb)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A chatbot-settings question is the licence message when those tools are locked.
     * AI Search settings and the data-source list are not a stand-in.
     */
    public static function chatbotSettingsNeedLicenceMessage(string $text, bool $chatbotToolLocked): bool
    {
        return $chatbotToolLocked && self::asksForChatbotSettings($text);
    }

    /**
     * Words that mean every source on the site, not one named source.
     */
    public static function askedForEverySource(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach ([
            'all sources',
            'all data sources',
            'all datasources',
            'every source',
            'every data source',
            'sync everything',
            'synchronise everything',
            'synchronize everything',
            'alle quellen',
            'alle datenquellen',
            'alles synchronisieren',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return preg_match('/\bsync all\b/u', $text) === 1
            && preg_match('/\b(sitemap|pdf|faq|q&a)\b/u', $text) !== 1;
    }

    /**
     * Decide the sync target from the editor's words and the sources on this site.
     *
     * @param list<array<string, mixed>> $sources
     * @return array{action: string, scope: string, dataId: int, options: list<string>}
     */
    public static function namedSyncDecision(string $text, array $sources, string $scope, int $dataId): array
    {
        $scope = strtolower(trim($scope)) === 'all' ? 'all' : 'one';
        $known = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $uid = (int) ($source['uid'] ?? 0);
            if ($uid > 0) {
                $known[$uid] = $source;
            }
        }
        if (self::askedForEverySource($text)) {
            return ['action' => 'keep', 'scope' => 'all', 'dataId' => 0, 'options' => []];
        }

        $namedUid = self::uidNamedInText($text, array_keys($known));
        if ($namedUid > 0 && isset($known[$namedUid])) {
            return ['action' => 'use', 'scope' => 'one', 'dataId' => $namedUid, 'options' => []];
        }

        $matches = self::matchingSourceIds($text, $known);
        if (count($matches) === 1) {
            return ['action' => 'use', 'scope' => 'one', 'dataId' => $matches[0], 'options' => []];
        }
        if (count($matches) > 1) {
            $namedUid = self::uidNamedInText($text, $matches);
            if ($namedUid > 0) {
                return ['action' => 'use', 'scope' => 'one', 'dataId' => $namedUid, 'options' => []];
            }

            return [
                'action' => 'ask',
                'scope' => 'one',
                'dataId' => 0,
                'options' => self::optionLabelsFor($matches, $known),
            ];
        }

        if ($scope === 'all') {
            $options = self::optionLabelsFor(array_keys($known), $known);
            if (count($known) === 1) {
                return ['action' => 'use', 'scope' => 'one', 'dataId' => (int) array_key_first($known), 'options' => []];
            }
            if ($options !== []) {
                return ['action' => 'ask', 'scope' => 'one', 'dataId' => 0, 'options' => $options];
            }

            return ['action' => 'keep', 'scope' => 'all', 'dataId' => 0, 'options' => []];
        }
        if ($dataId > 0 && isset($known[$dataId])) {
            return ['action' => 'keep', 'scope' => 'one', 'dataId' => $dataId, 'options' => []];
        }
        if ($dataId > 0) {
            return ['action' => 'notFound', 'scope' => 'one', 'dataId' => $dataId, 'options' => []];
        }

        return ['action' => 'keep', 'scope' => 'one', 'dataId' => 0, 'options' => []];
    }

    /**
     * @param array<int, array<string, mixed>> $known
     * @return list<int>
     */
    private static function matchingSourceIds(string $text, array $known): array
    {
        $quoted = self::quotedNames($text);
        if ($quoted !== []) {
            $ids = [];
            foreach ($known as $uid => $source) {
                $name = mb_strtolower(trim((string) ($source['name'] ?? '')));
                foreach ($quoted as $quote) {
                    if ($name !== '' && $name === mb_strtolower($quote)) {
                        $ids[] = (int) $uid;
                    }
                }
            }

            return $ids;
        }

        $types = self::typesNamedInText($text);
        $ids = [];
        foreach ($known as $uid => $source) {
            $name = trim((string) ($source['name'] ?? ''));
            $type = strtolower(trim((string) ($source['type'] ?? '')));
            if ($types !== []) {
                // "sitemap" must not also match a source whose name merely appears in the sentence ("pages").
                if (in_array($type, $types, true) || self::nameMatchesNamedType($name, $types)) {
                    $ids[] = (int) $uid;
                }
                continue;
            }
            if (self::textContainsName($text, $name)) {
                $ids[] = (int) $uid;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function quotedNames(string $text): array
    {
        if (preg_match_all('/[\'"“”]([^\'"“”]{2,})[\'"“”]/u', $text, $matches) < 1) {
            return [];
        }

        return array_values(array_filter(array_map(static fn(string $name): string => trim($name), $matches[1]), static fn(string $name): bool => $name !== ''));
    }

    /**
     * @return list<string>
     */
    private static function typesNamedInText(string $text): array
    {
        $text = mb_strtolower($text);
        $types = [];
        foreach ([
            'web pages' => 'web_pages',
            'web page' => 'web_pages',
            'typo3 pages' => 'typo3_pages',
            'typo3 page' => 'typo3_pages',
            'q & a' => 'qa',
            'q&a' => 'qa',
            'sitemap' => 'sitemap',
            'faq' => 'qa',
            'pdf' => 'pdf',
        ] as $phrase => $type) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $text) === 1) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * @param list<string> $types
     */
    private static function nameMatchesNamedType(string $name, array $types): bool
    {
        $name = mb_strtolower($name);
        foreach ($types as $type) {
            if ($type === 'sitemap' && str_contains($name, 'sitemap')) {
                return true;
            }
            if ($type === 'pdf' && str_contains($name, 'pdf')) {
                return true;
            }
            if ($type === 'qa' && (str_contains($name, 'faq') || str_contains($name, 'q&a') || str_contains($name, 'q & a'))) {
                return true;
            }
        }

        return false;
    }

    private static function textContainsName(string $text, string $name): bool
    {
        $name = trim(mb_strtolower($name));
        if (mb_strlen($name) < 4) {
            return false;
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/u';

        return preg_match($pattern, mb_strtolower($text)) === 1;
    }

    /**
     * @param list<int> $uids
     */
    private static function uidNamedInText(string $text, array $uids): int
    {
        $found = [];
        foreach ($uids as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $number = preg_quote((string) $uid, '/');
            $pattern = '/(?:\b(?:uid|dataid|data id|source|quelle)\s*#?\s*' . $number . '\b)|(?:\[' . $number . '\])/i';
            if (preg_match($pattern, $text) === 1) {
                $found[] = $uid;
            }
        }

        return count($found) === 1 ? $found[0] : 0;
    }

    /**
     * @param list<int> $ids
     * @param array<int, array<string, mixed>> $known
     * @return list<string>
     */
    private static function optionLabelsFor(array $ids, array $known): array
    {
        $rows = [];
        foreach ($ids as $id) {
            if (isset($known[$id])) {
                $rows[] = $known[$id];
            }
        }

        return self::queueSourceDecision($rows)['options'];
    }

    /**
     * One datasource is used at once. Several become buttons ("Name (type) [uid]"). None stays empty.
     *
     * @param list<array<string, mixed>> $sources
     * @return array{dataId: int, options: list<string>}
     */
    public static function queueSourceDecision(array $sources): array
    {
        $options = [];
        $onlyId = 0;
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $uid = (int) ($source['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $name = trim((string) ($source['name'] ?? ''));
            $type = trim((string) ($source['type'] ?? ''));
            $label = $name !== '' ? $name : 'Source';
            if ($type !== '') {
                $label .= ' (' . $type . ')';
            }
            $options[] = $label . ' [' . $uid . ']';
            $onlyId = $uid;
        }
        if (count($options) === 1) {
            return ['dataId' => $onlyId, 'options' => []];
        }

        return ['dataId' => 0, 'options' => $options];
    }

    /**
     * The datasource the editor picked from the training-queue question, or 0 when this message is not that answer.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function dataIdFromQueueChoice(string $reply, array $history): int
    {
        $question = self::openQuestionMeta($history);
        if ((string) ($question['askedFor'] ?? '') !== 't3cs_list_queue_items') {
            return 0;
        }
        $options = array_values(array_filter(
            is_array($question['options'] ?? null) ? $question['options'] : [],
            'is_string',
        ));
        $reply = trim($reply);
        foreach ($options as $option) {
            if (self::sameChoice($reply, $option)) {
                return self::uidInBrackets($option);
            }
        }
        $uid = 0;
        if (preg_match('/\[(\d+)\]\s*$/', $reply, $bracket) === 1) {
            $uid = (int) $bracket[1];
        } elseif (preg_match('/^\d+$/', $reply) === 1) {
            $uid = (int) $reply;
        }
        if ($uid > 0) {
            foreach ($options as $option) {
                if (self::uidInBrackets($option) === $uid) {
                    return $uid;
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $pendingArguments
     * @return array{turn: array{role: string, content: string, meta: array<string, mixed>}|null, dataId: int}
     */
    private function resolveMissingQueueSource(
        array $context,
        string $correlationId,
        string $questionKey = 'agent.queue.whichSource',
        string $askedFor = 't3cs_list_queue_items',
        array $pendingArguments = [],
    ): array
    {
        $rootPageId = $this->listingRootPageId($context);
        if ($rootPageId <= 0) {
            return ['turn' => $this->queueNotice($this->translator->translate('agent.queue.noSite'), $correlationId), 'dataId' => 0];
        }

        $listed = $this->playgroundService->invoke('t3cs_list_datasources', ['rootPageId' => $rootPageId]);
        $payload = $listed['result'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (($listed['success'] ?? false) !== true || !is_array($payload)) {
            $message = trim((string) ($listed['message'] ?? ''));

            return [
                'turn' => $this->queueNotice(
                    $message !== '' ? $message : $this->translator->translate('agent.queue.noSources'),
                    $correlationId,
                ),
                'dataId' => 0,
            ];
        }
        if (isset($payload['error']) && is_string($payload['error']) && trim($payload['error']) !== '') {
            return ['turn' => $this->queueNotice(trim($payload['error']), $correlationId), 'dataId' => 0];
        }

        $sources = is_array($payload['datasources'] ?? null) ? $payload['datasources'] : [];
        $decision = self::queueSourceDecision($sources);
        if ($decision['dataId'] > 0) {
            return ['turn' => null, 'dataId' => $decision['dataId']];
        }
        if ($decision['options'] === []) {
            return ['turn' => $this->queueNotice($this->translator->translate('agent.queue.noSources'), $correlationId), 'dataId' => 0];
        }

        return [
            'turn' => [
                'role' => 'assistant',
                'content' => $this->translator->translate($questionKey),
                'meta' => [
                    'type' => 'clarification',
                    'tool' => 'ask_clarification',
                    'options' => $decision['options'],
                    'contentChoice' => true,
                    'askedFor' => $askedFor,
                    'pendingArguments' => $pendingArguments,
                    'correlationId' => $correlationId,
                    'orchestratorPause' => true,
                ],
            ],
            'dataId' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function siteRootPageId(array $context): int
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        if ($pageId <= 0) {
            return 0;
        }
        try {
            return GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageId)->getRootPageId();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The Dashboard has no open page. One site in the installation is still a root page.
     */
    private function onlySiteRootPageId(): int
    {
        try {
            $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
        } catch (\Throwable) {
            return 0;
        }
        $ids = [];
        foreach ($sites as $site) {
            $id = $site->getRootPageId();
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return count($ids) === 1 ? (int) array_key_first($ids) : 0;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function listingRootPageId(array $context): int
    {
        $rootPageId = $this->siteRootPageId($context);

        return $rootPageId > 0 ? $rootPageId : $this->onlySiteRootPageId();
    }

    private function isSiteRoot(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }
        try {
            GeneralUtility::makeInstance(SiteFinder::class)->getSiteByRootPageId($pageId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A named source belongs to one site. Used only when the sent root is missing or not a site root.
     */
    private function siteIdOfDatasource(int $dataId): int
    {
        if ($dataId <= 0 || !class_exists(\NITSAN\NsT3Cs\Domain\Repository\DatasourceRepository::class)) {
            return 0;
        }
        try {
            $row = GeneralUtility::makeInstance(\NITSAN\NsT3Cs\Domain\Repository\DatasourceRepository::class)
                ->findOneByUidAndOptionalSiteId($dataId, 0);
        } catch (\Throwable) {
            return 0;
        }
        $siteId = is_array($row) ? (int) ($row['site_id'] ?? 0) : 0;

        return $this->isSiteRoot($siteId) ? $siteId : 0;
    }

    /**
     * A positive root stays when it is a real site root. A guessed id such as 1 does not.
     */
    public static function resolvedRootPageId(int $given, bool $givenIsSiteRoot, int $fromOpenPage, int $fromDataSource, int $onlySite): int
    {
        if ($given > 0 && $givenIsSiteRoot) {
            return $given;
        }
        if ($fromOpenPage > 0) {
            return $fromOpenPage;
        }
        if ($fromDataSource > 0) {
            return $fromDataSource;
        }

        return $onlySite > 0 ? $onlySite : 0;
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function queueNotice(string $content, string $correlationId): array
    {
        return [
            'role' => 'assistant',
            'content' => $content,
            'meta' => [
                'type' => 'nl_reply',
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
            ],
        ];
    }

    private static function uidInBrackets(string $label): int
    {
        if (preg_match('/\[(\d+)\]\s*$/', $label, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    /**
     * The editor clicked an option of the agent's image-text question ("Regenerate all", "Keep current
     * texts", or one of several files with the same name): the tool and target that click stands for.
     *
     * @param list<array<string, mixed>> $history
     * @return array{tool: string, arguments: array<string, int>}|null
     */
    public static function imageTextAnswer(string $reply, array $history): ?array
    {
        $question = self::openQuestionMeta($history);
        $asked = is_array($question['fileMetaOverwrite'] ?? null) ? $question['fileMetaOverwrite'] : [];
        $choices = is_array($asked['choices'] ?? null) ? $asked['choices'] : [];
        if ((int) ($asked['fileUid'] ?? 0) > 0) {
            foreach ($choices as $label) {
                if (self::sameChoice($reply, (string) $label)) {
                    return [
                        'tool' => 't3aa_update_file_metadata',
                        'arguments' => ['fileUid' => (int) $asked['fileUid'], 'languageUid' => (int) ($asked['languageUid'] ?? 0)],
                    ];
                }
            }
        }
        $chosenFileUid = ($question['fileChoice'] ?? false) === true ? self::contentUidFromChoiceText($reply) : 0;
        if ($chosenFileUid > 0) {
            return [
                'tool' => (string) ($question['fileChoiceTool'] ?? '') ?: 't3aa_update_file_metadata',
                'arguments' => ['fileUid' => $chosenFileUid],
            ];
        }

        return null;
    }

    /**
     * The file the editor picked from "Several files are named …" ("1:/folder/photo.jpg [134]").
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $question
     * @return array<string, mixed>
     */
    private static function withChosenFile(array $arguments, array $question, string $reply): array
    {
        $chosenFileUid = ($question['fileChoice'] ?? false) === true ? self::contentUidFromChoiceText($reply) : 0;
        if ($chosenFileUid > 0) {
            unset($arguments['fileUrl']);
            $arguments['fileUid'] = $chosenFileUid;
        }

        return $arguments;
    }

    /**
     * A bare file name that several files share: ask which one. The click goes back to $toolName.
     *
     * @param array<string, mixed> $arguments
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function fileChoiceTurn(array $arguments, string $toolName, string $correlationId): ?array
    {
        $fileUrl = trim((string) ($arguments['fileUrl'] ?? ''));
        if ((int) ($arguments['fileUid'] ?? 0) > 0 || $fileUrl === '' || str_contains(ltrim($fileUrl, '/'), '/')) {
            return null;
        }
        $sameName = self::filesNamed(basename($fileUrl));
        if (count($sameName) < 2) {
            return null;
        }

        return [
            'role' => 'assistant',
            'content' => $this->translator->translate('agent.fileMeta.whichFile', [basename($fileUrl)]),
            'meta' => [
                'type' => 'clarification',
                'tool' => 'ask_clarification',
                'options' => array_map(
                    static fn(array $row): string => sprintf('%d:%s [%d]', $row['storage'], $row['identifier'], $row['uid']),
                    $sameName,
                ),
                // Options are files, not pages.
                'contentChoice' => true,
                'fileChoice' => true,
                'fileChoiceTool' => $toolName,
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * Stored texts of the file the arguments point at, read with the file metadata read tool (no AI call).
     *
     * @param array<string, mixed> $arguments
     * @return array{fileUid: int, fileName: string, languageUid: int, fields: array{altText: string, title: string, description: string}}|null
     */
    private function currentFileMetadata(array $arguments): ?array
    {
        $read = array_filter([
            'fileUid' => (int) ($arguments['fileUid'] ?? 0),
            'fileUrl' => trim((string) ($arguments['fileUrl'] ?? '')),
            'languageUid' => (int) ($arguments['languageUid'] ?? 0),
            'isoCode' => trim((string) ($arguments['isoCode'] ?? '')),
        ], static fn(int|string $value): bool => $value !== 0 && $value !== '');
        $result = $this->playgroundService->invoke('t3aa_get_file_metadata', $read);
        if (($result['success'] ?? false) !== true) {
            // Unknown file, no access, …: the preview reports it.
            return null;
        }
        $payload = $result['result'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload) || ($payload['found'] ?? false) !== true || (int) ($payload['file_uid'] ?? 0) <= 0) {
            return null;
        }

        return [
            'fileUid' => (int) $payload['file_uid'],
            'fileName' => (string) ($payload['file_name'] ?? ''),
            'languageUid' => (int) ($payload['language_uid'] ?? 0),
            'fields' => [
                'altText' => (string) ($payload['alt_text'] ?? ''),
                'title' => (string) ($payload['title'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
            ],
        ];
    }

    /**
     * Meta of the agent's question the current message answers: the newest clarification with no editor
     * message after it.
     *
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    public static function openQuestionMeta(array $history): array
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $entry = $history[$i];
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($entry['role'] ?? '') === 'user' && ($meta['hidden'] ?? false) !== true) {
                return [];
            }
            if (($entry['role'] ?? '') === 'assistant' && ($meta['type'] ?? '') === 'clarification') {
                return $meta;
            }
        }

        return [];
    }

    public static function writtenInRequest(string $text, string $request): bool
    {
        $normalize = static fn(string $value): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));

        return $text !== '' && str_contains($normalize($request), $normalize($text));
    }

    public static function sameChoice(string $reply, string $choice): bool
    {
        $normalize = static fn(string $text): string => mb_strtolower(trim($text, " \t\n\r\0\x0B.!"));

        return $choice !== '' && $normalize($reply) === $normalize($choice);
    }

    /**
     * Files with that name the editor may read. Files outside their file mounts are not offered.
     *
     * @return list<array{uid: int, storage: int, identifier: string}>
     */
    private static function filesNamed(string $name): array
    {
        if ($name === '') {
            return [];
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('uid', 'storage', 'identifier')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq('name', $queryBuilder->createNamedParameter($name)),
                $queryBuilder->expr()->eq('missing', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->setMaxResults(50)
            ->executeQuery()
            ->fetchAllAssociative();

        $fileAccess = new RecordsApplyFileAccess(GeneralUtility::makeInstance(ResourceFactory::class));
        $files = [];
        foreach ($rows as $row) {
            if (count($files) >= 9) {
                break;
            }
            if (!$fileAccess->canRead((int) $row['uid'])) {
                continue;
            }
            $files[] = [
                'uid' => (int) $row['uid'],
                'storage' => (int) $row['storage'],
                'identifier' => (string) $row['identifier'],
            ];
        }

        return $files;
    }

    private static function languageTitle(int $languageUid): string
    {
        if ($languageUid <= 0) {
            return '';
        }
        foreach (GeneralUtility::makeInstance(SiteFinder::class)->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $language) {
                if ($language->getLanguageId() === $languageUid) {
                    return $language->getTitle();
                }
            }
        }

        return '';
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
        $arguments = self::withUpdateUid($arguments, $toolName, $history);
        $requestQuery = trim((string) ($body['requestQuery'] ?? ''));
        $arguments = self::seoQueueRemovePageIds($arguments, $toolName, $requestQuery);
        $arguments = self::seoQueueAddRecursiveSubtree($arguments, $toolName, $requestQuery);
        $arguments = self::seoBatchSubpagesOfOpenPage($arguments, $toolName, $requestQuery);
        $arguments = self::openPageAsParent($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $arguments = self::openPageAsCreatePid($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $arguments = self::openPageAsContentPid($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $arguments = self::pinOpenPageForQueueRemove($arguments, (int) ($context['pageId'] ?? 0), $toolName, $requestQuery);
        $gateRequest = trim((string) ($body['requestForGate'] ?? ''));
        $routerFileUids = is_array($body['routerFileUids'] ?? null)
            ? array_values(array_filter(array_map('intval', $body['routerFileUids']), static fn(int $uid): bool => $uid > 0))
            : [];
        $arguments = $this->enrichPlannedToolArguments($toolName, $arguments, $history, $gateRequest !== '' ? $gateRequest : $requestQuery, $routerFileUids);
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
            $requestQuery,
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
                    // Without the request, a refused call cannot be told apart from a wrong call afterwards.
                    'trace' => [[
                        'step' => 'plan',
                        'tool' => $toolName,
                        'request' => self::refusedRequestForTrace($arguments),
                        'response' => $exception->getMessage(),
                        'status' => 'refused',
                    ]],
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
        $extra = [];
        // Media CE create + place/attach (or "new Text & Media …"): after Apply, attach the pending file.
        // Clarification answers live in requestForGate / latest user text, not always in requestQuery.
        // "Put that picture on this page" alone must still wire pendingAttach when the model creates textmedia.
        if ($shownTool === 'write_table' && $plan->action === 'create') {
            $gateRequest = trim((string) ($body['requestForGate'] ?? ''));
            $latestUser = AgentPromptBuilder::latestUserRequestText($history);
            $attachProbe = $gateRequest !== '' ? $gateRequest : ($latestUser !== '' ? $latestUser : $requestQuery);
            $wantsAttach = AgentAttachTargetRequest::asksForNewContentElementAttach($requestQuery)
                || AgentAttachTargetRequest::asksForNewContentElementAttach($attachProbe)
                || (
                    self::planCreatesMediaContent($plan)
                    && (
                        AgentRequestedFiles::asksToAttachOrPlaceFile($requestQuery)
                        || AgentRequestedFiles::asksToAttachOrPlaceFile($attachProbe)
                    )
                );
            if ($wantsAttach) {
                // Only pending/generated files for this turn — never the first upload in the whole conversation.
                $pending = AgentPromptBuilder::pendingAttachFileUids($history, $attachProbe);
                $fileUid = $pending[0] ?? 0;
                if ($fileUid <= 0) {
                    $router = is_array($body['routerFileUids'] ?? null) ? $body['routerFileUids'] : [];
                    $fileUid = (int) ($router[0] ?? 0);
                }
                if ($fileUid > 0) {
                    $extra['pendingAttachFileUid'] = $fileUid;
                }
            }
        }
        $this->draftService->persistDraft($draftCard, $plan, $arguments, $this->draftSession, null, $extra);

        $content = ($draftCard['kind'] ?? '') === SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION
            ? (string) ($draftCard['summary'] ?? $this->translator->translate('agent.draft.proposed', [$editorLabel]))
            : $this->translator->translate('agent.draft.proposed', [$editorLabel]);
        if ($shownTool === 't3aa_accessibility_scan_run') {
            $confirm = $this->translator->translate('agent.turn.confirmAccessibilityScan');
            if ($confirm === 'agent.turn.confirmAccessibilityScan' || $confirm === '') {
                $confirm = 'Please confirm to start the scan.';
            }
            $content = $confirm;
        }
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
                'previews' => $this->previewsForChange($arguments, [], $history, $requestQuery),
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * Draft creates a content type that can hold a file (Text & Media / Text & Images / Image).
     */
    public static function planCreatesMediaContent(ToolPlan $plan): bool
    {
        foreach ($plan->fields as $field) {
            if ($field->table !== 'tt_content' || strtolower($field->field) !== 'ctype') {
                continue;
            }
            $cType = strtolower(trim((string) $field->proposedValue));

            return in_array($cType, ['textmedia', 'textpic', 'image'], true);
        }

        return false;
    }

    /**
     * "Add a text element … on this page": keep the create on the open page when the model drifts to Home.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function openPageAsContentPid(array $arguments, int $openPageId, string $toolName, string $requestQuery): array
    {
        if ($openPageId <= 0 || strtolower(trim($toolName)) !== 'write_table') {
            return $arguments;
        }
        $table = strtolower(trim((string) ($arguments['tableName'] ?? $arguments['table'] ?? '')));
        if ($table !== 'tt_content' || strtolower(trim((string) ($arguments['action'] ?? ''))) !== 'create') {
            return $arguments;
        }
        if (isset($arguments['beforeUid']) || isset($arguments['afterUid'])) {
            return $arguments;
        }
        if (preg_match('/\b(this|current|open)\s+page\b|\b(diese|aktuelle)\s+seite\b/iu', $requestQuery) !== 1) {
            return $arguments;
        }
        // Another named page in the request ("on the Contact page like this page"): leave the model's pid.
        if (AgentTargetPageResolver::namedPageTitle($requestQuery) !== null
            || AgentTargetPageResolver::mentionedPageUids($requestQuery) !== []) {
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
        // Negative pid means "after record X" — never rewrite that into "first on the open page".
        if ($pid <= 0 || $pid === $openPageId) {
            return $arguments;
        }
        $data['pid'] = $openPageId;
        $arguments['data'] = $wasString ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $data;

        return $arguments;
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
     * "SEO for all subpages of this page" must use the subpages. A list that is only the open page
     * is the page on screen, so it is dropped and the subpage lookup runs.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function seoBatchSubpagesOfOpenPage(array $arguments, string $toolName, string $requestQuery): array
    {
        if (strtolower(trim($toolName)) !== 't3ai_generate_seo_batch') {
            return $arguments;
        }
        if (preg_match(
            '/\b(?:all\s+(?:its\s+|the\s+)?subpages?|subpages?\s+of\s+(?:this|the|that)\s+page|und\s+alle\s+unterseiten|unterseiten\s+(?:dieser|der)\s+seite)\b/iu',
            $requestQuery,
        ) !== 1) {
            return $arguments;
        }
        $parent = (int) ($arguments['pageId'] ?? 0);
        if ($parent <= 0) {
            return $arguments;
        }
        $listed = self::positivePageIdList($arguments['pageIds'] ?? null);
        if ($listed !== [] && $listed !== [$parent]) {
            return $arguments;
        }
        unset($arguments['pageIds']);
        if (self::entriesNameOnlyPage($arguments['entries'] ?? null, $parent)) {
            unset($arguments['entries']);
        }

        return $arguments;
    }

    /**
     * @return list<int>
     */
    private static function positivePageIdList(mixed $pageIds): array
    {
        if (is_string($pageIds)) {
            $pageIds = preg_split('/[\s,;]+/', trim($pageIds, " \t\n\r\0\x0B\"'[]")) ?: [];
        }
        if (!is_array($pageIds)) {
            return [];
        }
        $ids = [];
        foreach ($pageIds as $pageId) {
            $pageId = (int) $pageId;
            if ($pageId > 0) {
                $ids[$pageId] = $pageId;
            }
        }

        return array_values($ids);
    }

    private static function entriesNameOnlyPage(mixed $entries, int $pageId): bool
    {
        if (is_string($entries)) {
            $decoded = json_decode($entries, true);
            $entries = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($entries) || $entries === []) {
            return false;
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return false;
            }
            $id = (int) ($entry['pageId'] ?? 0);
            unset($entry['pageId'], $entry['pageUrl']);
            if ($id !== $pageId || $entry !== []) {
                return false;
            }
        }

        return true;
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
        // The editor named the page that is open: that is the page to queue, not a different one.
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $openPage = is_array($details['page'] ?? null) ? $details['page'] : [];
        $openTitle = trim((string) ($openPage['title'] ?? ''));
        if ($openTitle !== '' && mb_strtolower($openTitle) === mb_strtolower($named)) {
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
    public static function pinTranslateContentToOpenPage(array $arguments, string $toolName, int $openPageId, string $requestQuery = ''): array
    {
        if (strtolower(trim($toolName)) !== 't3ai_translate_content' || $openPageId <= 0) {
            return $arguments;
        }
        $arguments['pageId'] = $openPageId;
        // A button the editor clicked ("Coastal travel [713]") is the element to translate.
        $chosen = self::chosenContentUidOnPage($requestQuery, $openPageId);
        if ($chosen > 0) {
            $arguments['contentUid'] = $chosen;

            return $arguments;
        }
        // "the first / second / last element": the position the editor named wins over a guessed uid.
        $position = self::requestedElementPosition($requestQuery);
        if ($position !== null) {
            $rows = self::defaultLanguageContentOnPage($openPageId);
            $row = $rows === [] ? null : ($position === -1 ? $rows[count($rows) - 1] : ($rows[$position - 1] ?? null));
            if (is_array($row) && (int) ($row['uid'] ?? 0) > 0) {
                $arguments['contentUid'] = (int) $row['uid'];

                return $arguments;
            }
        }
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

    /**
     * The position of the element a request names ("translate the first element into French"):
     * 1-based, or -1 for the last one. Null when the request names no position.
     */
    public static function requestedElementPosition(string $request): ?int
    {
        $noun = '(?:content\s+)?(?:element|paragraph|block|section|inhaltselement|absatz|abschnitt)';
        $words = [
            'first' => 1, '1st' => 1, 'erste' => 1,
            'second' => 2, '2nd' => 2, 'zweite' => 2,
            'third' => 3, '3rd' => 3, 'dritte' => 3,
            'fourth' => 4, '4th' => 4, 'vierte' => 4,
            'fifth' => 5, '5th' => 5, 'fünfte' => 5,
            'last' => -1, 'letzte' => -1,
        ];
        if (preg_match('/(?<![\p{L}\p{N}_])(' . implode('|', array_map('preg_quote', array_keys($words))) . ')(?:[nrsm])?\s+' . $noun . '(?![\p{L}\p{N}_])/iu', $request, $match) !== 1) {
            return null;
        }

        return $words[mb_strtolower($match[1])] ?? null;
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
        // The editor already picked one ("Coastal travel [713]"): do not ask again.
        if (self::chosenContentUidOnPage($requestQuery, $openPageId) > 0) {
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
                // The options are content elements, not pages: a click must not be read as a page choice.
                'contentChoice' => true,
                'correlationId' => $correlationId,
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * The uid in a picked option such as "Coastal travel [713]", or 0. Only a reply that ends with the
     * bracketed uid counts.
     */
    public static function contentUidFromChoiceText(string $request): int
    {
        return preg_match('/\[(\d{1,10})\]\s*$/u', trim($request), $match) === 1 ? (int) $match[1] : 0;
    }

    /** A picked element uid that really is an element of the open page, or 0. */
    private static function chosenContentUidOnPage(string $request, int $openPageId): int
    {
        $uid = self::contentUidFromChoiceText($request);

        return $uid > 0 && self::contentElementPid($uid) === $openPageId ? $uid : 0;
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
        if ($requestQuery === '' || (!RequestedLanguage::isLanguageTool($toolName) && !RequestedLanguage::isQueueAddRequestForRemoval($toolName, $requestQuery))) {
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
     * For a new textmedia draft, pending unattached files from this conversation are shown too
     * so "Put that picture on this page" is not an empty card.
     *
     * @param array<string, mixed> $arguments
     * @param array<mixed> $target
     * @param list<array<string, mixed>> $history
     * @return list<array{fileUid: int, url: string, href: string, name: string, alt: string}>
     */
    private function previewsForChange(array $arguments, array $target, array $history = [], string $request = ''): array
    {
        if (!isset($this->mediaPreviews)) {
            return [];
        }
        $previews = $this->mediaPreviews->forRecord((string) ($target['table'] ?? ''), (int) ($target['uid'] ?? 0));
        if ($previews !== []) {
            return $previews;
        }
        $previews = $this->mediaPreviews->forDetails($arguments);
        if ($previews !== []) {
            return $previews;
        }
        $pending = AgentPromptBuilder::pendingAttachFileUids($history, $request);
        if ($pending === []) {
            return [];
        }

        return $this->mediaPreviews->forDetails([
            'fileUids' => implode(',', array_map(static fn(int $uid): string => (string) $uid, $pending)),
        ]);
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

        // A limit of 1 hides a second element with a similar name, so the Agent never sees an ambiguity.
        if (strtolower(trim($toolName)) === 'content_search') {
            $searchLimit = (int) ($arguments['limit'] ?? 20);
            if ($searchLimit > 0 && $searchLimit < 5) {
                $arguments['limit'] = 5;
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
        if (
            !str_starts_with(strtolower(trim($toolName)), 'workspace_')
            && !in_array(strtolower(trim($toolName)), self::WORKSPACE_EXEMPT_TOOLS, true)
        ) {
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

        $toolKey = strtolower(trim($toolName));
        if (in_array($toolKey, self::ROOT_PAGE_TOOLS, true)) {
            $given = (int) ($arguments['rootPageId'] ?? 0);
            $givenIsSiteRoot = $this->isSiteRoot($given);
            $fromOpenPage = $this->siteRootPageId($context);
            $fromDataSource = 0;
            $onlySite = 0;
            if (!$givenIsSiteRoot && $fromOpenPage <= 0) {
                $fromDataSource = $this->siteIdOfDatasource((int) ($arguments['dataId'] ?? 0));
                if ($fromDataSource <= 0) {
                    $onlySite = $this->onlySiteRootPageId();
                }
            }
            $rootPageId = self::resolvedRootPageId($given, $givenIsSiteRoot, $fromOpenPage, $fromDataSource, $onlySite);
            if ($rootPageId > 0) {
                $arguments['rootPageId'] = $rootPageId;
            } elseif ($given > 0) {
                unset($arguments['rootPageId']);
            }
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
     * @param list<int> $routerFileUids sys_file uids the structural attach router picked (not from the model)
     * @return array<string, mixed>
     */
    private function enrichPlannedToolArguments(string $toolName, array $arguments, array $history, string $request = '', array $routerFileUids = []): array
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
            // "description" is a common alias; an empty prompt with a picture request uses the editor's words.
            $prompt = trim((string) ($arguments['prompt'] ?? ''));
            if ($prompt === '') {
                $prompt = trim((string) ($arguments['description'] ?? ''));
            }
            if ($prompt === '' && $request !== '' && AgentRequestedFiles::asksForGeneratedImage($request)) {
                $prompt = $request;
            }
            if ($prompt !== '') {
                $arguments['prompt'] = $prompt;
                unset($arguments['description']);
            }

            return $arguments;
        }

        if ($toolName === 't3aa_update_file_metadata') {
            $named = self::withRequestedFileName($arguments, $request, null, $history);
            if ($named !== $arguments) {
                return $named;
            }

            return $this->enrichFileMetadataArguments($arguments, $history, $request);
        }

        if ($toolName === 't3aa_mark_image_decorative') {
            return self::withRequestedFileName($arguments, $request, null, $history);
        }

        if ($toolName !== 'file_reference_add') {
            return $arguments;
        }

        if (AgentPromptBuilder::hasOpenFileReferenceDraft($history)) {
            throw new \InvalidArgumentException(
                'An attach card is already open for the editor. Wait until they Apply or Decline it; do not prepare another attach.',
            );
        }

        // The router already picked the uploaded file (the composer file of this turn, or the one waiting
        // upload of the chat); the history gate below only guards file uids that a model made up.
        $fileUidsRaw = trim((string) ($arguments['fileUids'] ?? ''));
        if ($routerFileUids !== [] && self::fileUidsArgumentIsNumericList($fileUidsRaw)) {
            $named = array_values(array_filter(array_map('intval', explode(',', $fileUidsRaw)), static fn(int $uid): bool => $uid > 0));
            if ($named !== [] && array_diff($named, $routerFileUids) === []) {
                return $arguments;
            }
        }

        $pending = AgentPromptBuilder::pendingAttachFileUids($history, $request);
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
    private function enrichFileMetadataArguments(array $arguments, array $history, string $request = ''): array
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

        $nextMissing = self::nextMissingAltFile($history, $request);
        if ($nextMissing !== null) {
            $arguments['fileUid'] = $nextMissing['fileUid'];
            unset($arguments['fileUrl']);

            return $arguments;
        }

        if (AgentRunner::isMissingAltTextRequest($request)) {
            throw new \InvalidArgumentException(
                'No listed image without alt text is left. Call t3aa_list_files_missing_alt_text first, then t3aa_update_file_metadata with the fileUid of the next listed file.',
            );
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

    /**
     * The editor named one file ("…/photo.jpg"). That file is the target, not a file the model found
     * by browsing with a similar name. The file tool resolves the name to its sys_file record.
     *
     * Several named files: the next one that has no image-text card yet. A fileUid of a file with
     * that name (picked when several share the name) stays.
     *
     * @param array<string, mixed> $arguments
     * @param (callable(int): string)|null $fileNameOf sys_file name by uid
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    public static function withRequestedFileName(array $arguments, string $request, ?callable $fileNameOf = null, array $history = []): array
    {
        $name = self::nextRequestedFileName($request, $history);
        if ($name === null) {
            return $arguments;
        }

        $fileNameOf ??= static fn(int $uid): string => (string) (BackendUtility::getRecord('sys_file', $uid, 'name')['name'] ?? '');
        $fileUid = (int) ($arguments['fileUid'] ?? 0);
        if ($fileUid > 0 && strcasecmp($fileNameOf($fileUid), basename($name)) === 0) {
            unset($arguments['fileUrl']);

            return $arguments;
        }

        unset($arguments['fileUid']);
        $currentUrl = trim((string) ($arguments['fileUrl'] ?? ''));
        if ($currentUrl === '' || strcasecmp(basename($currentUrl), basename($name)) !== 0) {
            $arguments['fileUrl'] = $name;
        }

        return $arguments;
    }

    /**
     * File names with a file extension in the request, in order, unique by basename.
     *
     * @return list<string>
     */
    public static function requestedFileNames(string $request): array
    {
        if ($request === '') {
            return [];
        }
        preg_match_all(
            '#(?<![\w.-])((?:[\w.-]+/)*[\w.-]+\.(?:jpe?g|png|webp|gif|svg|avif|tiff?|bmp|heic))(?![\w.-])#iu',
            $request,
            $matches,
        );
        $names = [];
        $seen = [];
        foreach ($matches[1] as $name) {
            $name = ltrim($name, '/');
            $key = strtolower(basename($name));
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * One file name with a file extension in the request, or null when there is none or several.
     */
    public static function requestedFileName(string $request): ?string
    {
        $names = self::requestedFileNames($request);

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * The next named file that still needs image texts. Several names: the first not yet prepared.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function nextRequestedFileName(string $request, array $history = []): ?string
    {
        $names = self::requestedFileNames($request);
        if ($names === []) {
            return null;
        }
        $done = self::processedImageTextBasenames($history);
        foreach ($names as $name) {
            if (!isset($done[strtolower(basename($name))])) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A Keep / Regenerate click is the current message; the named files live on the original request.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function namedImageTextRequest(string $requestQuery, array $history): string
    {
        if (self::requestedFileNames($requestQuery) !== []) {
            return $requestQuery;
        }
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $text = trim((string) ($history[$i]['content'] ?? ''));
            if (self::requestedFileNames($text) !== []) {
                return $text;
            }
        }

        return $requestQuery;
    }

    /**
     * Named files that still need an image-text card.
     *
     * @param list<array<string, mixed>> $history
     * @return list<string>
     */
    public static function remainingNamedImageTextFiles(array $history, string $request): array
    {
        if (
            preg_match('/\b(alt\s*text|image\s*texts?|file\s*meta|metadata|bildtext\w*|alternativtext\w*|datei\s*meta)\b/iu', $request) !== 1
            || AgentRunner::isDecorativeImageRequest($request)
            || AgentRunner::isQueueFolderAltTextRequest($request)
        ) {
            return [];
        }
        $done = self::processedImageTextBasenames($history);
        $left = [];
        foreach (self::requestedFileNames($request) as $name) {
            if (!isset($done[strtolower(basename($name))])) {
                $left[] = $name;
            }
        }

        return $left;
    }

    /**
     * After listing images without alt text, the next ones the editor asked to fill (e.g. the first three).
     *
     * @param list<array<string, mixed>> $history
     * @return list<array{fileUid: int, identifier: string}>
     */
    public static function remainingMissingAltFiles(array $history, string $request): array
    {
        if (!AgentRunner::isMissingAltTextRequest($request) || !self::asksToWriteMissingAltText($request)) {
            return [];
        }
        $listed = self::listedMissingAltFiles($history);
        if ($listed === []) {
            return [];
        }
        $limit = self::requestedMissingAltCount($request);
        if ($limit > 0) {
            $listed = array_slice($listed, 0, $limit);
        }
        $done = self::processedImageTextFileUids($history);
        $left = [];
        foreach ($listed as $file) {
            if (!isset($done[$file['fileUid']])) {
                $left[] = $file;
            }
        }

        return $left;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    public static function remainingImageTextReminder(array $history, string $request): string
    {
        $named = self::remainingNamedImageTextFiles($history, $request);
        if ($named !== []) {
            $next = $named[0];
            $rest = array_slice($named, 1);

            return $rest === []
                ? 'Remaining: call t3aa_update_file_metadata with fileUrl "' . $next . '". One file per call.'
                : 'Remaining: call t3aa_update_file_metadata with fileUrl "' . $next . '" (then '
                    . implode(', ', array_map(static fn(string $name): string => '"' . $name . '"', $rest))
                    . ' after Apply). One file per call.';
        }
        $missing = self::remainingMissingAltFiles($history, $request);
        if ($missing === []) {
            return '';
        }
        $next = $missing[0];
        $left = count($missing);

        return sprintf(
            'Remaining: call t3aa_update_file_metadata with fileUid %d (%s). %d listed image(s) still need alt text. One file per call; after Apply continue with the next listed file. Skip files that already have alt text.',
            $next['fileUid'],
            $next['identifier'],
            $left,
        );
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array{fileUid: int, identifier: string}|null
     */
    public static function nextMissingAltFile(array $history, string $request): ?array
    {
        $left = self::remainingMissingAltFiles($history, $request);

        return $left[0] ?? null;
    }

    public static function asksToWriteMissingAltText(string $request): bool
    {
        return preg_match(
            '/\b(generat\w*|write|save|fill|create|pick|suggest|erstell\w*|generier\w*|schreib\w*|speicher\w*|füll\w*|wähl\w*)\b/iu',
            $request,
        ) === 1;
    }

    /**
     * "pick the first three", "the first 3 images", "drei Bilder".
     */
    public static function requestedMissingAltCount(string $request): int
    {
        $words = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5, 'sechs' => 6];
        if (preg_match(
            '/\b(?:first|erste[ns]?)\s+(\d{1,2}|two|three|four|five|six|zwei|drei|vier|fünf|sechs)\b/iu',
            $request,
            $match,
        ) === 1) {
            return $words[mb_strtolower($match[1])] ?? (int) $match[1];
        }
        if (preg_match(
            '/\b(\d{1,2}|two|three|four|five|six|zwei|drei|vier|fünf|sechs)\s+(?:[\p{L}-]+\s+){0,3}(?:images?|files?|pictures?|photos?|bilder|dateien|fotos)\b/iu',
            $request,
            $match,
        ) === 1) {
            return $words[mb_strtolower($match[1])] ?? (int) $match[1];
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<string, true> lowercase basename => true
     */
    public static function processedImageTextBasenames(array $history): array
    {
        $names = [];
        foreach (self::processedImageTextTargets($history) as $target) {
            $name = strtolower(basename($target['name']));
            if ($name !== '' && $name !== '.' && $name !== '/') {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<int, true>
     */
    public static function processedImageTextFileUids(array $history): array
    {
        $uids = [];
        foreach (self::processedImageTextTargets($history) as $target) {
            if ($target['fileUid'] > 0) {
                $uids[$target['fileUid']] = true;
            }
        }

        return $uids;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array{fileUid: int, identifier: string}>
     */
    public static function listedMissingAltFiles(array $history): array
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (
                ($history[$i]['role'] ?? '') !== 'assistant'
                || (string) ($meta['tool'] ?? '') !== 't3aa_list_files_missing_alt_text'
                || ($meta['success'] ?? true) === false
            ) {
                continue;
            }
            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            $items = is_array($details['items'] ?? null) ? $details['items'] : [];
            $files = [];
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $uid = (int) ($row['file_uid'] ?? $row['fileUid'] ?? $row['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $files[] = [
                    'fileUid' => $uid,
                    'identifier' => (string) ($row['identifier'] ?? $row['name'] ?? (string) $uid),
                ];
            }

            return $files;
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array{fileUid: int, name: string}>
     */
    private static function processedImageTextTargets(array $history): array
    {
        $targets = [];
        foreach ($history as $entry) {
            if (($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $tool = (string) ($meta['tool'] ?? $meta['askedFor'] ?? '');
            if (!in_array($tool, ['t3aa_update_file_metadata', 't3aa_mark_image_decorative'], true)) {
                continue;
            }
            $type = (string) ($meta['type'] ?? '');
            if (!in_array($type, ['suggestions', 'tool_result', 'clarification', 'readback_result'], true)) {
                continue;
            }
            foreach (is_array($meta['previews'] ?? null) ? $meta['previews'] : [] as $preview) {
                if (!is_array($preview)) {
                    continue;
                }
                $targets[] = [
                    'fileUid' => (int) ($preview['fileUid'] ?? 0),
                    'name' => (string) ($preview['name'] ?? ''),
                ];
            }
            $overwrite = is_array($meta['fileMetaOverwrite'] ?? null) ? $meta['fileMetaOverwrite'] : [];
            if ((int) ($overwrite['fileUid'] ?? 0) > 0) {
                $targets[] = [
                    'fileUid' => (int) $overwrite['fileUid'],
                    'name' => (string) ($overwrite['fileName'] ?? ''),
                ];
            }
            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            $uid = (int) ($details['file_uid'] ?? $details['fileUid'] ?? 0);
            $name = (string) ($details['identifier'] ?? $details['fileName'] ?? $details['name'] ?? '');
            if ($uid > 0 || $name !== '') {
                $targets[] = ['fileUid' => $uid, 'name' => $name];
            }
        }

        return $targets;
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

        $rerouted = [];
        $fileUid = (int) ($arguments['fileUid'] ?? 0);
        $metadataUid = (int) ($arguments['uid'] ?? 0);
        if ($fileUid <= 0 && $metadataUid > 0) {
            // "uid" of write_table on sys_file_metadata is the metadata row, not the file.
            $row = BackendUtility::getRecord('sys_file_metadata', $metadataUid, 'file,sys_language_uid');
            if (is_array($row)) {
                $fileUid = (int) ($row['file'] ?? 0);
                $rerouted['languageUid'] = (int) ($row['sys_language_uid'] ?? 0);
            }
        }
        if ($fileUid > 0) {
            $rerouted['fileUid'] = $fileUid;
        }

        $data = $arguments['data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        if (is_array($data)) {
            foreach (['alternative' => 'altText', 'title' => 'title', 'description' => 'description'] as $column => $argument) {
                if (is_string($data[$column] ?? null) && trim($data[$column]) !== '') {
                    $rerouted[$argument] = $data[$column];
                }
            }
        }

        return [
            'tool' => 't3aa_update_file_metadata',
            'arguments' => $rerouted,
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
     * A write_table update that arrives without "uid" gets it from where models usually put it ("data":{"uid":…},
     * "recordUid", "id"), else from the one record of that table the agent just read in this turn
     * ("Make the German version visible": pages_get uid 70, then update). Several candidates: nothing is guessed.
     *
     * @param array<string, mixed> $arguments
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    public static function withUpdateUid(array $arguments, string $toolName, array $history): array
    {
        if (strtolower(trim($toolName)) !== 'write_table' || strtolower(trim((string) ($arguments['action'] ?? ''))) !== 'update') {
            return $arguments;
        }
        if ((int) ($arguments['uid'] ?? 0) > 0) {
            return $arguments;
        }

        $data = $arguments['data'] ?? [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        foreach ([$arguments['recordUid'] ?? null, $arguments['id'] ?? null, is_array($data) ? ($data['uid'] ?? null) : null] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $arguments['uid'] = (int) $candidate;

                return $arguments;
            }
        }

        $table = strtolower(trim((string) ($arguments['tableName'] ?? $arguments['table'] ?? '')));
        $readTool = ['pages' => 'pages_get', 'tt_content' => 'content_get'][$table] ?? '';
        if ($readTool === '') {
            return $arguments;
        }
        $uids = [];
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $entry = $history[$i];
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($entry['role'] ?? '') === 'user' && ($meta['hidden'] ?? false) !== true) {
                break;
            }
            if (($meta['type'] ?? '') !== 'tool_result' || ($meta['success'] ?? true) === false || ($meta['tool'] ?? '') !== $readTool) {
                continue;
            }
            $uid = (int) (is_array($meta['details'] ?? null) ? ($meta['details']['uid'] ?? 0) : 0);
            if ($uid > 0) {
                $uids[$uid] = true;
            }
        }
        if (count($uids) === 1) {
            $arguments['uid'] = (int) array_key_first($uids);
        }

        return $arguments;
    }

    /**
     * The arguments of a refused plan for the stored trace: long values are shortened, nothing else is changed.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function refusedRequestForTrace(array $arguments): array
    {
        $short = static function (mixed $value) use (&$short): mixed {
            if (is_array($value)) {
                return array_map($short, $value);
            }

            return is_string($value) && mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '… (shortened)' : $value;
        };

        return array_map($short, $arguments);
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
     * A read sent to the search-settings write tool is the get tool. A real update stays a write.
     *
     * @param array<string, mixed> $body
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array<string, mixed>|null
     */
    private function searchSettingsReadTool(string $toolName, array &$body, array $catalog): ?array
    {
        if ($toolName !== 't3as_search_settings') {
            return null;
        }
        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $operation = strtolower(trim((string) ($arguments['operation'] ?? 'update')));
        if ($operation === 'update') {
            return null;
        }
        $getTool = $this->findTool($catalog, 't3as_search_settings_get');
        if ($getTool === null || ($getTool['executable'] ?? false) !== true) {
            return null;
        }
        unset($arguments['operation'], $arguments['settingsJson']);
        $body['arguments'] = $arguments;

        return $getTool;
    }

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
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $presented
     * @return array<string, mixed>
     */
    private function withPresentedLines(array $meta, array $presented): array
    {
        $lines = $presented['lines'] ?? null;
        if (is_array($lines) && $lines !== []) {
            $meta['lines'] = array_values($lines);
        }

        return $meta;
    }

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
