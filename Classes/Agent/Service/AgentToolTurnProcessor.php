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

        if (($tool['executable'] ?? false) !== true) {
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

        $severity = (string) ($tool['severity'] ?? '');
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
            $draftMessage = $this->processWriteToolTurn($tool, $context, $body, $severity);
            $draftMessage['meta']['correlationId'] = $correlationId;
            $this->applyTurnGuardMeta($draftMessage['meta'], $guard['message']);

            return $draftMessage;
        }

        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $arguments = $this->mergeContextArguments($arguments, $context);

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
        );

        $content = (string) $presented['content'];
        $facts = $presented['facts'];
        $details = $presented['details'];
        if ($this->governanceGuard->requiresPiiMasking($user)) {
            $content = $this->governanceGuard->maskPii($content);
            $facts = $this->maskPresentedFacts($facts);
            $details = $this->maskPresentedDetails($details);
        }

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
            'rawResult' => $result['result'] ?? null,
        ];
        $this->applyTurnGuardMeta($meta, $guard['message']);
        if ($details !== null) {
            $meta['details'] = $details;
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
        $arguments = $this->mergeContextArguments($arguments, $context);

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
        );

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
        $arguments = $this->mergeContextArguments($arguments, $context);

        $variants = max(1, min(5, (int) ($arguments['variants'] ?? 3)));
        unset($arguments['variants']);

        $result = $this->playgroundService->preview($toolName, $arguments, $variants);
        if (($result['success'] ?? false) !== true || !$result['preview'] instanceof PreviewResult) {
            return [
                'role' => 'assistant',
                'content' => $this->translator->translate(
                    'agent.turn.previewFailed',
                    [$toolName, (string) ($result['message'] ?? '')],
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
                'callCount' => $preview->callCount,
                'generationPath' => $preview->generationPath,
                'correlationId' => $correlationId,
                'latencyMs' => (int) ($result['latencyMs'] ?? 0),
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function processWriteToolTurn(array $tool, array $context, array $body, string $severity): array
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
        $arguments = $this->mergeContextArguments($arguments, $context);
        $arguments = $this->normalizeWriteToolArguments($toolName, $arguments);

        try {
            $plan = $this->toolPlanResolver->plan($toolName, $arguments);
        } catch (UnsupportedPlanException $exception) {
            return [
                'role' => 'assistant',
                'content' => $exception->getMessage(),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'orchestratorPause' => true],
            ];
        } catch (\Throwable $exception) {
            return [
                'role' => 'assistant',
                'content' => $this->translator->translate('agent.turn.planFailed', [$toolName, $exception->getMessage()]),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'orchestratorPause' => true],
            ];
        }

        $editorLabel = $this->editorLabelService->resolve($tool);
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
                'tool' => $toolName,
                'editorLabel' => $editorLabel,
                'severity' => $severity,
                'draft' => $draftCard,
                'orchestratorPause' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function mergeContextArguments(array $arguments, array $context): array
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        if ($pageId > 0) {
            $arguments['pageId'] ??= $pageId;
            $arguments['pid'] ??= $pageId;
            $arguments['uid'] ??= $pageId;
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

        $workspaceId = (int) ($context['workspaceId'] ?? 0);
        if ($workspaceId > 0) {
            $arguments['workspaceId'] ??= $workspaceId;
        }

        $storageUid = (int) ($context['storageUid'] ?? 0);
        if ($storageUid > 0) {
            $arguments['storageUid'] ??= $storageUid;
        }

        return $arguments;
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
                ? ($result['result'] ?? null)
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
     * @param list<array{label: string, value: string}> $facts
     * @return list<array{label: string, value: string}>
     */
    private function maskPresentedFacts(array $facts): array
    {
        return array_map(
            fn(array $fact): array => [
                'label' => (string) ($fact['label'] ?? ''),
                'value' => $this->governanceGuard->maskPii((string) ($fact['value'] ?? '')),
            ],
            $facts,
        );
    }

    private function maskPresentedDetails(mixed $details): mixed
    {
        try {
            $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return is_string($details)
                ? $this->governanceGuard->maskPii($details)
                : $details;
        }

        $masked = $this->governanceGuard->maskPii($encoded);
        try {
            return json_decode($masked, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $masked;
        }
    }

}
