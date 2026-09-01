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

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Exception\UnsupportedPlanException;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Shared tool-turn execution for slash/@ fast paths and NL orchestration.
 *
 * @internal
 */
final readonly class AgentToolTurnProcessor
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
        $tool = $this->findTool($catalog, $toolName);
        if ($tool === null) {
            return [
                'role' => 'assistant',
                'content' => $this->translate('agent.turn.unknownTool', [$toolName]),
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
            'toolCallLabel' => (string) ($tool['name'] ?? ''),
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
                'content' => $this->translate('agent.turn.planUnsupported', [$toolName]),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'orchestratorPause' => true],
            ];
        }

        $arguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $arguments = $this->mergeContextArguments($arguments, $context);

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
                'content' => $this->translate('agent.turn.planFailed', [$toolName, $exception->getMessage()]),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'orchestratorPause' => true],
            ];
        }

        $draftCard = $this->draftService->buildDraftCard($plan, $severity);
        $this->draftService->persistDraft($draftCard, $plan, $arguments, $this->draftSession);

        $content = ($draftCard['kind'] ?? '') === SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION
            ? (string) ($draftCard['summary'] ?? $this->translate('agent.draft.proposed', [$toolName]))
            : $this->translate('agent.draft.proposed', [$toolName]);

        return [
            'role' => 'assistant',
            'content' => $content,
            'meta' => [
                'type' => 'inline_draft',
                'tool' => $toolName,
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
}
