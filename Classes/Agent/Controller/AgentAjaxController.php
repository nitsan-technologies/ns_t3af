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

namespace NITSAN\NsT3AF\Agent\Controller;

use GuzzleHttp\Psr7\PumpStream;
use NITSAN\NsT3AF\Access\RecordAccessGate;
use NITSAN\NsT3AF\Agent\Context\AgentContextResolver;
use NITSAN\NsT3AF\Agent\Service\AgentAuditLogger;
use NITSAN\NsT3AF\Agent\Service\AgentAvailabilityService;
use NITSAN\NsT3AF\Agent\Service\AgentCompoundFlowService;
use NITSAN\NsT3AF\Agent\Service\AgentConversationSession;
use NITSAN\NsT3AF\Agent\Service\AgentDraftSession;
use NITSAN\NsT3AF\Agent\Service\AgentGovernanceGuard;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentMessageParser;
use NITSAN\NsT3AF\Agent\Service\AgentNlIntentResolver;
use NITSAN\NsT3AF\Agent\Service\AgentReadFastPathService;
use NITSAN\NsT3AF\Agent\Service\AgentRecordAttachmentResolver;
use NITSAN\NsT3AF\Agent\Service\AgentSchedulerHandoff;
use NITSAN\NsT3AF\Agent\Service\AgentSeoMetadataFlow;
use NITSAN\NsT3AF\Agent\Service\AgentStarterBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentTargetPageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use NITSAN\NsT3AF\Agent\Service\AgentTurnOrchestrator;
use NITSAN\NsT3AF\Agent\Service\AgentTurnRepository;
use NITSAN\NsT3AF\Agent\Service\AgentUndoService;
use NITSAN\NsT3AF\Agent\Service\AgentWorkflowService;
use NITSAN\NsT3AF\Agent\Service\AgentWriteService;
use NITSAN\NsT3AF\Agent\Service\PermittedActionProvider;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * JSON endpoints for the global AI Agent chat surface.
 *
 * @internal
 */
final class AgentAjaxController
{
    private const UPLOAD_MAX_BYTES = 104857600;

    public function __construct(
        private readonly AgentAvailabilityService $agentAvailability,
        private readonly AgentConversationSession $conversationSession,
        private readonly AgentDraftSession $draftSession,
        private readonly AgentWriteService $writeService,
        private readonly AgentUndoService $undoService,
        private readonly AgentGovernanceGuard $governanceGuard,
        private readonly AgentAuditLogger $auditLogger,
        private readonly AgentTurnRepository $turnRepository,
        private readonly AgentSchedulerHandoff $schedulerHandoff,
        private readonly PermittedActionProvider $permittedActionProvider,
        private readonly AgentStarterBuilder $starterBuilder,
        private readonly AgentSeoMetadataFlow $seoMetadataFlow,
        private readonly AgentRecordAttachmentResolver $recordAttachmentResolver,
        private readonly AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
        private readonly AgentContextResolver $agentContextResolver,
        private readonly FileService $fileService,
        private readonly WorkspaceListService $workspaceListService,
        private readonly ModuleTabUtility $moduleTabUtility,
        private readonly RecordAccessGate $recordAccessGate,
        private readonly UriBuilder $uriBuilder,
        private readonly ConnectionPool $connectionPool,
        private readonly AgentToolTurnProcessor $toolTurnProcessor,
        private readonly AgentTurnOrchestrator $turnOrchestrator,
        private readonly AgentMessageParser $messageParser,
        private readonly AgentNlIntentResolver $nlIntentResolver,
        private readonly AgentReadFastPathService $readFastPathService,
        private readonly AgentTargetPageResolver $targetPageResolver,
        private readonly AgentCompoundFlowService $compoundFlowService,
        private readonly AgentWorkflowService $workflowService,
    ) {}

    public function toolsAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $catalog = $this->buildToolCatalog();
        if ($query !== '') {
            $needle = strtolower($query);
            $filter = static fn(array $tool): bool => str_contains(strtolower($tool['name']), $needle)
                || str_contains(strtolower($tool['description']), $needle);
            $catalog['executable'] = array_values(array_filter($catalog['executable'], $filter));
            $catalog['locked'] = array_values(array_filter($catalog['locked'], $filter));
        }

        return new JsonResponse(['ok' => true, 'tools' => $catalog]);
    }

    public function recordsAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $parsedBody = $request->getParsedBody();
        $pageId = (int) ($request->getQueryParams()['pageId'] ?? (is_array($parsedBody) ? ($parsedBody['pageId'] ?? 0) : 0));

        return new JsonResponse([
            'ok' => true,
            'records' => $this->searchAttachableRecords($query, $pageId),
        ]);
    }

    public function conversationAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        if ($request->getMethod() === 'POST') {
            return $this->conversationSaveAction($request);
        }

        $context = $this->resolveContext($request);
        $this->bindConversationScope($context);
        $messages = array_map(static function (array $message): array {
            if (is_array($message['meta'] ?? null)) {
                $message['meta'] = self::sanitizeMessageMetaStatic($message['meta']);
            }

            return $message;
        }, $this->conversationSession->getMessages());
        $starters = $this->buildStarters($context);

        return new JsonResponse([
            'ok' => true,
            'messages' => $messages,
            'context' => $context,
            'starters' => $starters,
            'greeting' => $this->buildGreeting($context, $starters),
            'disclosureDismissed' => $this->conversationSession->isDisclosureDismissed(),
        ]);
    }

    public function conversationSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $messages = $body['messages'] ?? [];
        if (!is_array($messages)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid payload'], 400);
        }

        $context = is_array($body['context'] ?? null) ? $body['context'] : [];
        $this->bindConversationScope($context);

        $normalized = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
            $normalized[] = [
                'role' => (string) ($message['role'] ?? 'assistant'),
                'content' => (string) ($message['content'] ?? ''),
                'meta' => $this->sanitizeMessageMeta($meta),
            ];
        }

        $this->conversationSession->saveMessages($normalized);

        $context = $body['context'] ?? null;
        if (is_array($context)) {
            $this->conversationSession->saveContext($context);
        }

        if (array_key_exists('disclosureDismissed', $body)) {
            $this->conversationSession->setDisclosureDismissed((bool) $body['disclosureDismissed']);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function settingsLinkAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $pageId = (int) ($request->getQueryParams()['pageId'] ?? 0);
        $route = $this->moduleTabUtility->routeFor('aiAgent') ?? 't3af_dashboard.overview';
        $parameters = [];
        if ($pageId > 0) {
            $parameters['id'] = $pageId;
        }

        return new JsonResponse([
            'ok' => true,
            'route' => $route,
            'href' => (string) $this->uriBuilder->buildUriFromRoute($route, $parameters),
            'label' => $this->translate('agent.modal.settings'),
        ]);
    }

    public function applyDraftAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $body = $this->parseRequestBody($request);
        $draftId = trim((string) ($body['draftId'] ?? ''));
        $keptFieldKeys = is_array($body['keptFieldKeys'] ?? null) ? array_values(array_map('strval', $body['keptFieldKeys'])) : [];
        $applyMode = trim((string) ($body['applyMode'] ?? 'all'));
        $workspaceId = (int) ($body['workspaceId'] ?? 0);
        $correlationId = trim((string) ($body['correlationId'] ?? ''));

        if ($draftId === '') {
            return new JsonResponse(['ok' => false, 'message' => 'Missing draftId'], 400);
        }

        $workspaceBlock = $this->governanceGuard->assertDraftApplyAllowed($user, $workspaceId);
        if ($workspaceBlock !== null) {
            return new JsonResponse(['ok' => false, 'message' => $workspaceBlock], 403);
        }

        $storedDraft = $this->draftSession->getDraft($draftId);
        if ($storedDraft === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Draft not found'], 404);
        }

        if ($applyMode === 'safe') {
            $plan = ToolPlan::fromArray(is_array($storedDraft['plan'] ?? null) ? $storedDraft['plan'] : []);
            $keptFieldKeys = $this->lowRiskFieldMatrix->filterSafeFieldKeys($plan, $keptFieldKeys);
            if ($keptFieldKeys === []) {
                return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.draft.noSafeFields')], 400);
            }
        }

        $this->applyWorkspaceContext($user, $workspaceId);

        try {
            $result = $this->writeService->apply(
                $draftId,
                $keptFieldKeys,
                $correlationId !== '' ? $correlationId : null,
            );
        } catch (\Throwable $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
        }

        $this->auditLogger->logToolInvocation(
            (string) ($result['correlationId'] ?? $correlationId),
            (string) ($result['tool'] ?? 'agent_draft_apply'),
            ['draftId' => $draftId, 'keptFieldKeys' => $keptFieldKeys],
            true,
            0,
        );

        $user = $this->resolveBackendUser();
        $storedDraft = $this->draftSession->getDraft($draftId);
        $flow = is_array($storedDraft) ? (string) ($storedDraft['flow'] ?? '') : '';
        $handoff = $user !== null
            ? $this->schedulerHandoff->buildHandoffForApplyResult($result, [], $user, $flow !== '' ? $flow : null)
            : null;

        return new JsonResponse([
            'ok' => true,
            'result' => $result,
            'message' => ($result['toolConfirmation'] ?? false) === true
                ? $this->translate('agent.draft.toolApplied')
                : $this->translate('agent.draft.applied', [
                    (string) ($result['appliedCount'] ?? 0),
                    (string) ($result['totalCount'] ?? 0),
                ]),
            'schedulerHandoff' => $handoff,
        ]);
    }

    public function uploadAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $upload = $this->resolveUploadedFile($request);
        if ($upload === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.upload.missingFile')], 400);
        }

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.upload.failed')], 400);
        }

        $size = (int) $upload->getSize();
        if ($size <= 0) {
            return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.upload.empty')], 400);
        }
        if ($size > self::UPLOAD_MAX_BYTES) {
            return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.upload.tooLarge')], 400);
        }

        $parsedBody = $request->getParsedBody();
        $storageUid = (int) (is_array($parsedBody) ? ($parsedBody['storageUid'] ?? 1) : 1);
        $directoryPath = trim((string) (is_array($parsedBody) ? ($parsedBody['directoryPath'] ?? '/user_upload/') : '/user_upload/'));
        if ($directoryPath === '') {
            $directoryPath = '/user_upload/';
        }
        if (!str_starts_with($directoryPath, '/')) {
            $directoryPath = '/' . $directoryPath;
        }

        $clientName = (string) $upload->getClientFilename();
        $fileName = $this->sanitizeUploadFileName($clientName);
        if ($fileName === '') {
            return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.upload.invalidName')], 400);
        }

        $content = (string) $upload->getStream()->getContents();
        if ($content === '') {
            return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.upload.empty')], 400);
        }

        try {
            $result = $this->fileService->uploadFile($storageUid, $directoryPath, $fileName, $content);
        } catch (\Throwable $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
        }

        $identifier = (string) ($result['identifier'] ?? '');
        if ($identifier === '') {
            return new JsonResponse(['ok' => false, 'message' => $this->translate('agent.upload.failed')], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'file' => $result,
            'attachment' => $this->recordAttachmentResolver->formatFileAttachmentToken($storageUid, $identifier),
            'message' => $this->translate('agent.upload.success', [$fileName]),
        ]);
    }

    public function discardDraftAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $draftId = trim((string) ($body['draftId'] ?? ''));
        if ($draftId === '') {
            return new JsonResponse(['ok' => false, 'message' => 'Missing draftId'], 400);
        }

        $this->draftSession->removeDraft($draftId);

        return new JsonResponse([
            'ok' => true,
            'message' => $this->translate('agent.draft.discarded'),
        ]);
    }

    public function confirmDestructiveAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $draftId = trim((string) ($body['draftId'] ?? ''));
        if ($draftId === '') {
            return new JsonResponse(['ok' => false, 'message' => 'Missing draftId'], 400);
        }

        $draft = $this->draftSession->getDraft($draftId);
        if ($draft === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Draft not found'], 404);
        }

        $this->draftSession->setDestructiveArmed($draftId, true);

        return new JsonResponse([
            'ok' => true,
            'destructiveArmed' => true,
            'message' => $this->translate('agent.draft.destructiveArmed'),
        ]);
    }

    public function undoChangeAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $changeId = trim((string) ($body['changeId'] ?? ''));
        if ($changeId === '') {
            return new JsonResponse(['ok' => false, 'message' => 'Missing changeId'], 400);
        }

        try {
            $result = $this->undoService->undo($changeId);
        } catch (\Throwable $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
        }

        return new JsonResponse([
            'ok' => true,
            'result' => $result,
            'message' => $this->translate('agent.draft.undone'),
        ]);
    }

    public function turnAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return new JsonResponse(['ok' => false, 'message' => 'Empty message'], 400);
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $governanceBlock = $this->governanceGuard->assertTurnAllowed($user, $body);
        if ($governanceBlock !== null) {
            return new JsonResponse([
                'ok' => true,
                'messages' => [[
                    'role' => 'assistant',
                    'content' => $governanceBlock,
                    'meta' => ['type' => 'governance_blocked'],
                ]],
            ]);
        }

        $correlationId = $this->turnRepository->startTurn((int) ($user->user['uid'] ?? 0));

        $context = $this->resolveContext($request, is_array($body['context'] ?? null) ? $body['context'] : []);
        $context = $this->applyTargetPageFromMessage($message, $context, $user);
        $this->bindConversationScope($context);
        $selectedTool = trim((string) ($body['tool'] ?? ''));
        $toolArguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $starterAction = trim((string) ($body['action'] ?? ''));
        if ($selectedTool === '') {
            $parsed = $this->messageParser->extractSlashCommand($message);
            $selectedTool = $parsed['name'];
            if ($toolArguments === [] && $parsed['arguments'] !== []) {
                $toolArguments = $parsed['arguments'];
            }
        }
        $recordAttachments = $this->recordAttachmentResolver->extractAttachments($message);
        $fileAttachments = $this->recordAttachmentResolver->extractFileAttachments($message);
        if ($selectedTool !== '' && $toolArguments === [] && $recordAttachments !== []) {
            $toolArguments = $this->recordAttachmentResolver->mergeUidFromAttachments($selectedTool, $recordAttachments);
        }
        if ($starterAction === '' && $selectedTool === 'generate_seo_metadata') {
            $starterAction = 'generate_seo_metadata';
            $selectedTool = '';
        }
        if ($starterAction === '') {
            $starterAction = $this->workflowService->resolveStarterAction($message, $fileAttachments);
        }

        $messages = $this->conversationSession->getMessages();
        $messages[] = [
            'role' => 'user',
            'content' => $message,
            'meta' => ['correlationId' => $correlationId],
        ];

        $assistantMessages = [];
        $compoundSteps = $this->nlIntentResolver->resolveCompoundSteps($message);
        if ($this->nlIntentResolver->isCompoundTranslateSeoFlow($compoundSteps)) {
            $assistantMessages = $this->compoundFlowService->execute(
                $compoundSteps,
                (int) ($context['pageId'] ?? 0),
                $correlationId,
                $user,
            );
        } elseif ($starterAction === 'generate_seo_metadata') {
            $assistantMessages = $this->seoMetadataFlow->execute(
                (int) ($context['pageId'] ?? 0),
                $correlationId,
            );
            $assistantMessages = $this->prependTranslateSkippedNotice($message, $assistantMessages, $correlationId);
        } elseif (($workflowMessages = $this->workflowService->tryExecute(
            $message,
            $context,
            $body,
            $user,
            $correlationId,
            $recordAttachments,
            $fileAttachments,
        )) !== null) {
            $assistantMessages = $workflowMessages;
        } elseif ($selectedTool !== '') {
            $body['arguments'] = $toolArguments;
            $assistantMessages[] = $this->processToolTurn($selectedTool, $context, $body, $user, $correlationId);
        } else {
            $assistantMessages = $this->resolveNaturalLanguageTurn(
                $message,
                $recordAttachments,
                $fileAttachments,
                $context,
                $body,
                $user,
                $correlationId,
                $messages,
            );
        }

        foreach ($assistantMessages as $assistantMessage) {
            $messages[] = $assistantMessage;
        }

        $this->conversationSession->saveMessages($messages);
        $this->conversationSession->saveContext($context);

        return new JsonResponse([
            'ok' => true,
            'messages' => $assistantMessages,
            'context' => $context,
            'starters' => $this->buildStarters($context),
            'correlationId' => $correlationId,
        ]);
    }

    public function streamAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $body = $this->parseRequestBody($request);
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return new JsonResponse(['ok' => false, 'message' => 'Empty message'], 400);
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $governanceBlock = $this->governanceGuard->assertTurnAllowed($user, $body);
        if ($governanceBlock !== null) {
            return new JsonResponse([
                'ok' => true,
                'messages' => [[
                    'role' => 'assistant',
                    'content' => $governanceBlock,
                    'meta' => ['type' => 'governance_blocked'],
                ]],
            ]);
        }

        $correlationId = $this->turnRepository->startTurn((int) ($user->user['uid'] ?? 0));
        $context = $this->resolveContext($request, is_array($body['context'] ?? null) ? $body['context'] : []);
        $context = $this->applyTargetPageFromMessage($message, $context, $user);
        $this->bindConversationScope($context);
        $messages = $this->conversationSession->getMessages();
        $messages[] = [
            'role' => 'user',
            'content' => $message,
            'meta' => ['correlationId' => $correlationId],
        ];

        $selectedTool = trim((string) ($body['tool'] ?? ''));
        $toolArguments = is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $starterAction = trim((string) ($body['action'] ?? ''));
        if ($selectedTool === '') {
            $parsed = $this->messageParser->extractSlashCommand($message);
            $selectedTool = $parsed['name'];
            if ($toolArguments === [] && $parsed['arguments'] !== []) {
                $toolArguments = $parsed['arguments'];
            }
        }
        $recordAttachments = $this->recordAttachmentResolver->extractAttachments($message);
        $fileAttachments = $this->recordAttachmentResolver->extractFileAttachments($message);
        if ($selectedTool !== '' && $toolArguments === [] && $recordAttachments !== []) {
            $toolArguments = $this->recordAttachmentResolver->mergeUidFromAttachments($selectedTool, $recordAttachments);
        }
        if ($starterAction === '' && $selectedTool === 'generate_seo_metadata') {
            $starterAction = 'generate_seo_metadata';
            $selectedTool = '';
        }
        if ($starterAction === '') {
            $starterAction = $this->workflowService->resolveStarterAction($message, $fileAttachments);
        }
        $body['arguments'] = $toolArguments;
        $compoundSteps = $this->nlIntentResolver->resolveCompoundSteps($message);
        $useCompoundFlow = $this->nlIntentResolver->isCompoundTranslateSeoFlow($compoundSteps);
        $useFastPathOnly = $selectedTool !== '' || $starterAction !== '' || $useCompoundFlow;

        $streamBody = new PumpStream(function () use (
            $useFastPathOnly,
            $compoundSteps,
            $message,
            $messages,
            $context,
            $body,
            $user,
            $correlationId,
            $selectedTool,
            $starterAction,
            $recordAttachments,
            $fileAttachments,
        ): string|false {
            static $emitted = false;
            if ($emitted) {
                return false;
            }
            $emitted = true;

            $this->configureSseStream();
            $assistantMessages = [];

            try {
                if ($useFastPathOnly) {
                    $assistantMessages = $this->resolveFastPathMessages(
                        $message,
                        $context,
                        $body,
                        $user,
                        $correlationId,
                        $selectedTool,
                        $starterAction,
                        $recordAttachments,
                        $fileAttachments,
                        $compoundSteps,
                    );
                    foreach ($assistantMessages as $assistantMessage) {
                        $this->emitSseEvent('message', ['message' => $assistantMessage]);
                    }
                } else {
                    $assistantMessages = $this->resolveNaturalLanguageTurn(
                        $message,
                        $recordAttachments,
                        $fileAttachments,
                        $context,
                        $body,
                        $user,
                        $correlationId,
                        $messages,
                        function (string $event, array $payload): void {
                            $this->emitSseEvent($event, $payload);
                        },
                    );
                }

                foreach ($assistantMessages as $assistantMessage) {
                    $messages[] = $assistantMessage;
                }
                $this->conversationSession->saveMessages($messages);
                $this->conversationSession->saveContext($context);

                $this->emitSseEvent('done', [
                    'ok' => true,
                    'messages' => $assistantMessages,
                    'context' => $context,
                    'starters' => $this->buildStarters($context),
                    'correlationId' => $correlationId,
                ]);
            } catch (\Throwable $exception) {
                $this->emitSseEvent('error', [
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ]);
            }

            return '';
        });

        return new Response(
            $streamBody,
            200,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * @param list<array{table: string, uid: int}> $attachments
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function processRecordAttachmentTurns(
        array $attachments,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        if ($attachments === []) {
            return [];
        }

        $catalog = $this->buildToolCatalog();
        $messages = [];

        foreach ($attachments as $attachment) {
            $table = (string) ($attachment['table'] ?? '');
            $uid = (int) ($attachment['uid'] ?? 0);
            $invocation = $this->recordAttachmentResolver->resolveReadInvocation(
                $table,
                $uid,
                fn(string $tool): bool => $this->findTool($catalog, $tool) !== null,
            );

            if ($invocation === null) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $this->translate('agent.turn.unknownAttachment', [$table, (string) $uid]),
                    'meta' => [
                        'type' => 'error',
                        'correlationId' => $correlationId,
                        'attachedRecord' => $attachment,
                    ],
                ];
                continue;
            }

            $body['arguments'] = $invocation['arguments'];
            $message = $this->processToolTurn($invocation['tool'], $context, $body, $user, $correlationId);
            $message['meta']['attachedRecord'] = $attachment;
            $message['meta']['triggeredByAttachment'] = true;
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param list<array{storageUid: int, identifier: string}> $attachments
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function processFileAttachmentTurns(
        array $attachments,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        if ($attachments === []) {
            return [];
        }

        $catalog = $this->buildToolCatalog();
        $messages = [];

        foreach ($attachments as $attachment) {
            $storageUid = (int) ($attachment['storageUid'] ?? 0);
            $identifier = (string) ($attachment['identifier'] ?? '');
            $invocation = $this->recordAttachmentResolver->resolveFileReadInvocation(
                $storageUid,
                $identifier,
                fn(string $tool): bool => $this->findTool($catalog, $tool) !== null,
            );

            if ($invocation === null) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $this->translate('agent.turn.unknownFileAttachment', [$identifier]),
                    'meta' => [
                        'type' => 'error',
                        'correlationId' => $correlationId,
                        'attachedFile' => $attachment,
                    ],
                ];
                continue;
            }

            $body['arguments'] = $invocation['arguments'];
            $message = $this->processToolTurn($invocation['tool'], $context, $body, $user, $correlationId);
            $message['meta']['attachedFile'] = $attachment;
            $message['meta']['triggeredByAttachment'] = true;
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function processToolTurn(
        string $toolName,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        return $this->toolTurnProcessor->execute($toolName, $context, $body, $user, $correlationId);
    }

    /**
     * @param list<array{table: string, uid: int}> $recordAttachments
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array<string, mixed>> $historyMessages
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function resolveNaturalLanguageTurn(
        string $message,
        array $recordAttachments,
        array $fileAttachments,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $historyMessages,
        ?callable $emitEvent = null,
    ): array {
        $readFastPath = $this->readFastPathService->resolve(
            $message,
            $context,
            $body,
            $user,
            $correlationId,
        );
        if ($readFastPath !== []) {
            $this->emitAssistantStreamMessages($emitEvent, $readFastPath);

            return $readFastPath;
        }

        $workflowMessages = $this->workflowService->tryExecute(
            $message,
            $context,
            $body,
            $user,
            $correlationId,
            $recordAttachments,
            $fileAttachments,
        );
        if ($workflowMessages !== null && $workflowMessages !== []) {
            $this->emitAssistantStreamMessages($emitEvent, $workflowMessages);

            return $workflowMessages;
        }

        $attachmentMessages = $this->processRecordAttachmentTurns(
            $recordAttachments,
            $context,
            $body,
            $user,
            $correlationId,
        );
        if ($attachmentMessages === []) {
            $attachmentMessages = $this->processFileAttachmentTurns(
                $fileAttachments,
                $context,
                $body,
                $user,
                $correlationId,
            );
        }

        $followUp = $this->messageParser->stripComposerTokens($message);
        if ($followUp !== '' && $attachmentMessages !== []) {
            $followUpWorkflow = $this->workflowService->tryExecute(
                $followUp,
                $context,
                $body,
                $user,
                $correlationId,
                $recordAttachments,
                $fileAttachments,
            );
            if ($followUpWorkflow !== null && $followUpWorkflow !== []) {
                $merged = array_merge($attachmentMessages, $followUpWorkflow);
                $this->emitAssistantStreamMessages($emitEvent, $merged);

                return $merged;
            }

            $history = $historyMessages;
            foreach ($attachmentMessages as $attachmentMessage) {
                $history[] = $attachmentMessage;
            }
            $orchestratorResult = $this->turnOrchestrator->runTurn(
                $followUp,
                $history,
                $context,
                $body,
                $user,
                $correlationId,
                $emitEvent,
            );

            return array_merge($attachmentMessages, $orchestratorResult['messages']);
        }

        if ($attachmentMessages !== []) {
            $this->emitAssistantStreamMessages($emitEvent, $attachmentMessages);

            return $attachmentMessages;
        }

        $orchestratorResult = $this->turnOrchestrator->runTurn(
            $message,
            $historyMessages,
            $context,
            $body,
            $user,
            $correlationId,
            $emitEvent,
        );

        return $orchestratorResult['messages'];
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     * @param list<array{role: string, content: string, meta: array<string, mixed>}> $messages
     */
    private function emitAssistantStreamMessages(?callable $emitEvent, array $messages): void
    {
        if ($emitEvent === null) {
            return;
        }
        foreach ($messages as $message) {
            $emitEvent('message', ['message' => $message]);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array{table: string, uid: int}> $attachments
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @param list<string> $compoundSteps
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function resolveFastPathMessages(
        string $message,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        string $selectedTool,
        string $starterAction,
        array $attachments,
        array $fileAttachments = [],
        array $compoundSteps = [],
    ): array {
        if ($this->nlIntentResolver->isCompoundTranslateSeoFlow($compoundSteps)) {
            return $this->compoundFlowService->execute(
                $compoundSteps,
                (int) ($context['pageId'] ?? 0),
                $correlationId,
                $user,
            );
        }

        if ($starterAction === 'generate_seo_metadata') {
            return $this->prependTranslateSkippedNotice(
                $message,
                $this->seoMetadataFlow->execute(
                    (int) ($context['pageId'] ?? 0),
                    $correlationId,
                ),
                $correlationId,
            );
        }

        if (($workflowMessages = $this->workflowService->tryExecute(
            $message,
            $context,
            $body,
            $user,
            $correlationId,
            $attachments,
            $fileAttachments,
        )) !== null) {
            return $workflowMessages;
        }

        if ($selectedTool !== '') {
            return [$this->processToolTurn($selectedTool, $context, $body, $user, $correlationId)];
        }

        $messages = $this->processRecordAttachmentTurns(
            $attachments,
            $context,
            $body,
            $user,
            $correlationId,
        );
        if ($messages !== []) {
            return $messages;
        }

        return $this->processFileAttachmentTurns(
            $fileAttachments,
            $context,
            $body,
            $user,
            $correlationId,
        );
    }

    private function configureSseStream(): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emitSseEvent(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
        if (function_exists('flush')) {
            flush();
        }
    }

    /**
     * @return array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    private function buildToolCatalog(): array
    {
        return $this->permittedActionProvider->buildCatalog();
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
     * @param array<string, mixed> $context
     * @return array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    private function buildStarters(array $context): array
    {
        return $this->starterBuilder->build($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function bindConversationScope(array $context): void
    {
        $this->conversationSession->setScope(
            trim((string) ($context['module'] ?? '')),
            (int) ($context['pageId'] ?? 0),
        );

        $user = $this->resolveBackendUser();
        if ($user !== null) {
            $this->applyWorkspaceContext($user, (int) ($context['workspaceId'] ?? 0));
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function applyTargetPageFromMessage(
        string $message,
        array $context,
        BackendUserAuthentication $user,
    ): array {
        $fallbackPageId = (int) ($context['pageId'] ?? 0);
        $targetPageId = $this->targetPageResolver->resolveFromMessage($message, $fallbackPageId, $user);
        if ($targetPageId <= 0 || $targetPageId === $fallbackPageId) {
            return $context;
        }

        $context['pageId'] = $targetPageId;
        if (!is_array($context['chips'] ?? null)) {
            return $context;
        }

        foreach ($context['chips'] as $index => $chip) {
            if (!is_array($chip) || (string) ($chip['key'] ?? '') !== 'page') {
                continue;
            }
            $context['chips'][$index]['value'] = $this->resolvePageTitle($targetPageId) . ' [' . $targetPageId . ']';
        }

        return $context;
    }

    /**
     * @param list<array{role: string, content: string, meta: array<string, mixed>}> $messages
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function prependTranslateSkippedNotice(
        string $message,
        array $messages,
        string $correlationId,
    ): array {
        if (!preg_match('/\b(translate|translation|localize|localise)\b/i', $message)) {
            return $messages;
        }

        array_unshift($messages, [
            'role' => 'assistant',
            'content' => $this->translate('agent.turn.translateNotInCombinedFlow'),
            'meta' => [
                'type' => 'info',
                'correlationId' => $correlationId,
                'skippedStep' => 'translate',
            ],
        ]);

        return $messages;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function sanitizeMessageMeta(array $meta): array
    {
        return self::sanitizeMessageMetaStatic($meta);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function sanitizeMessageMetaStatic(array $meta): array
    {
        if (array_key_exists('turnGuardWarning', $meta)) {
            $warning = $meta['turnGuardWarning'];
            if ($warning === null || $warning === '' || $warning === 'null') {
                unset($meta['turnGuardWarning']);
            }
        }

        if (array_key_exists('details', $meta) && $meta['details'] === null) {
            unset($meta['details']);
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $context
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $starters
     * @return array<string, mixed>
     */
    private function buildGreeting(array $context, array $starters): array
    {
        $page = '';
        $module = trim((string) ($context['module'] ?? ''));
        $brand = '';
        $language = '';

        foreach ($context['chips'] ?? [] as $chip) {
            if (!is_array($chip)) {
                continue;
            }
            $key = (string) ($chip['key'] ?? '');
            $value = (string) ($chip['value'] ?? '');
            if ($key === 'page') {
                $page = $value;
            }
            if ($key === 'brand') {
                $brand = $value;
            }
            if ($key === 'language') {
                $language = $value;
            }
        }

        return [
            'page' => $page,
            'module' => $module,
            'language' => $language,
            'brand' => $brand,
            'executableCount' => count($starters['executable']),
            'lockedCount' => count($starters['locked']),
        ];
    }

    /**
     * @param array<string, mixed> $clientContext
     * @return array<string, mixed>
     */
    private function resolveContext(ServerRequestInterface $request, array $clientContext = []): array
    {
        $query = $request->getQueryParams();
        $body = $request->getMethod() === 'POST' ? $this->parseRequestBody($request) : [];

        $merged = array_merge([
            'pageId' => (int) ($body['pageId'] ?? $query['pageId'] ?? $query['id'] ?? 0),
            'module' => trim((string) ($body['module'] ?? $query['module'] ?? '')),
            'record' => is_array($clientContext['record'] ?? null) ? $clientContext['record'] : null,
            'languageId' => (int) ($clientContext['languageId'] ?? $body['languageId'] ?? 0),
            'siteIdentifier' => trim((string) ($clientContext['siteIdentifier'] ?? '')),
            'workspaceId' => (int) ($clientContext['workspaceId'] ?? 0),
        ], $clientContext);

        $resolved = $this->agentContextResolver->resolve($merged, $this->resolveBackendUser());

        $chips = [];
        if ($resolved->module !== '') {
            $chips[] = [
                'key' => 'module',
                'label' => $this->translate('agent.context.module'),
                'value' => $resolved->module,
            ];
        }
        if ($resolved->pageId > 0) {
            $chips[] = [
                'key' => 'page',
                'label' => $this->translate('agent.context.page'),
                'value' => $this->resolvePageTitle($resolved->pageId) . ' [' . $resolved->pageId . ']',
            ];
        }
        if ($resolved->focusedRecord !== null) {
            $chips[] = [
                'key' => 'record',
                'label' => $this->translate('agent.context.record'),
                'value' => $resolved->focusedRecord['table'] . ':' . $resolved->focusedRecord['uid'],
            ];
        }
        if ($resolved->brandContextProfileUid !== null) {
            $chips[] = [
                'key' => 'brand',
                'label' => $this->translate('agent.context.brand'),
                'value' => $resolved->brandName !== ''
                    ? $resolved->brandName
                    : ('Profile #' . $resolved->brandContextProfileUid),
            ];
        }
        if ($resolved->languageId > 0) {
            $chips[] = [
                'key' => 'language',
                'label' => $this->translate('agent.context.language'),
                'value' => $this->resolveLanguageTitle($resolved->languageId),
            ];
        }
        $chips[] = [
            'key' => 'workspace',
            'label' => $this->translate('agent.context.workspace'),
            'value' => $this->workspaceListService->resolveTitle($resolved->workspaceId),
        ];

        return [
            'pageId' => $resolved->pageId,
            'module' => $resolved->module,
            'record' => $resolved->focusedRecord,
            'languageId' => $resolved->languageId,
            'workspaceId' => $resolved->workspaceId,
            'chips' => $chips,
            'contextAware' => $resolved->pageId > 0
                || $resolved->focusedRecord !== null
                || $resolved->module !== ''
                || $resolved->languageId > 0
                || $resolved->brandContextProfileUid !== null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchAttachableRecords(string $query, int $pageId): array
    {
        $user = $this->resolveBackendUser();
        if ($user === null || !$this->recordAccessGate->canSelectTable($user, 'pages')) {
            return [];
        }

        $connection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->setMaxResults(12)
            ->orderBy('title');

        if ($query !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like(
                        'title',
                        $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($query) . '%'),
                    ),
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter((int) $query, Connection::PARAM_INT),
                    ),
                ),
            );
        }

        $records = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid <= 0 || !$this->userCanReadPage($uid)) {
                continue;
            }
            $records[] = [
                'table' => 'pages',
                'uid' => $uid,
                'label' => trim((string) ($row['title'] ?? '')) !== '' ? (string) $row['title'] : 'Page ' . $uid,
                'severity' => ToolSeverity::Read->value,
            ];
        }

        if ($pageId > 0 && $this->recordAccessGate->canSelectTable($user, 'tt_content')) {
            $contentConnection = $this->connectionPool->getConnectionForTable('tt_content');
            $contentQuery = $contentConnection->createQueryBuilder();
            $contentQuery->getRestrictions()->removeAll();
            $contentQuery
                ->select('uid', 'header')
                ->from('tt_content')
                ->where(
                    $contentQuery->expr()->eq('pid', $contentQuery->createNamedParameter($pageId, Connection::PARAM_INT)),
                    $contentQuery->expr()->eq('deleted', 0),
                )
                ->setMaxResults(8)
                ->orderBy('sorting');

            if ($query !== '') {
                $contentQuery->andWhere(
                    $contentQuery->expr()->like(
                        'header',
                        $contentQuery->createNamedParameter('%' . $contentQuery->escapeLikeWildcards($query) . '%'),
                    ),
                );
            }

            foreach ($contentQuery->executeQuery()->fetchAllAssociative() as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $records[] = [
                    'table' => 'tt_content',
                    'uid' => $uid,
                    'label' => trim((string) ($row['header'] ?? '')) !== '' ? (string) $row['header'] : 'Content ' . $uid,
                    'severity' => ToolSeverity::Read->value,
                ];
            }
        }

        return $records;
    }

    private function resolvePageTitle(int $pageId): string
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $title = $queryBuilder
            ->select('title')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_string($title) && trim($title) !== '' ? $title : 'Page';
    }

    private function resolveLanguageTitle(int $languageId): string
    {
        if ($languageId <= 0) {
            return '';
        }

        $connection = $this->connectionPool->getConnectionForTable('sys_language');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $title = $queryBuilder
            ->select('title')
            ->from('sys_language')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_string($title) && trim($title) !== '' ? $title : ('Language #' . $languageId);
    }

    private function userCanReadPage(int $pageId): bool
    {
        $user = $this->resolveBackendUser();
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    private function denyUnlessAvailable(): ?JsonResponse
    {
        if ($this->agentAvailability->isAvailable()) {
            return null;
        }

        return new JsonResponse(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRequestBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function resolveBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }

    private function applyWorkspaceContext(BackendUserAuthentication $user, int $workspaceId): void
    {
        if ($workspaceId <= 0 || !AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return;
        }

        $user->setWorkspace($workspaceId);
    }

    private function resolveUploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        $candidate = $uploadedFiles['file'] ?? $uploadedFiles['upload'] ?? null;
        if ($candidate instanceof UploadedFileInterface) {
            return $candidate;
        }

        foreach ($uploadedFiles as $upload) {
            if ($upload instanceof UploadedFileInterface) {
                return $upload;
            }
        }

        return null;
    }

    private function sanitizeUploadFileName(string $clientName): string
    {
        $baseName = basename(str_replace('\\', '/', $clientName));
        $baseName = preg_replace('/[^\w.\-()+ ]+/u', '_', $baseName) ?? '';
        $baseName = trim($baseName, ".\t\n\r\0\x0B");

        return $baseName;
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

        // Labels use sprintf placeholders (%1$s / %2$s).
        return sprintf($value, ...array_map(static fn(int|string $argument): string => (string) $argument, $arguments));
    }
}
