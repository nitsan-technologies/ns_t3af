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
use NITSAN\NsT3AF\Agent\Context\AgentContextPresenter;
use NITSAN\NsT3AF\Agent\Service\AgentAuditLogger;
use NITSAN\NsT3AF\Agent\Service\AgentAvailabilityService;
use NITSAN\NsT3AF\Agent\Service\AgentConversationRecorder;
use NITSAN\NsT3AF\Agent\Service\AgentConversationSession;
use NITSAN\NsT3AF\Agent\Service\AgentConversationSummarizer;
use NITSAN\NsT3AF\Agent\Service\AgentCreditsStatus;
use NITSAN\NsT3AF\Agent\Service\AgentDraftSession;
use NITSAN\NsT3AF\Agent\Service\AgentGovernanceGuard;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentProviderOptions;
use NITSAN\NsT3AF\Agent\Service\AgentRecordAttachmentResolver;
use NITSAN\NsT3AF\Agent\Service\AgentRecordLabeler;
use NITSAN\NsT3AF\Agent\Service\AgentSchedulerHandoff;
use NITSAN\NsT3AF\Agent\Service\AgentSessionPresenter;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Agent\Service\AgentStarterBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentTargetPageResolver;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use NITSAN\NsT3AF\Agent\Service\AgentTurnRepository;
use NITSAN\NsT3AF\Agent\Service\AgentTurnRouter;
use NITSAN\NsT3AF\Agent\Service\AgentUndoService;
use NITSAN\NsT3AF\Agent\Service\AgentWorkspaceTarget;
use NITSAN\NsT3AF\Agent\Service\AgentWriteService;
use NITSAN\NsT3AF\Agent\Service\PermittedActionProvider;
use NITSAN\NsT3AF\Domain\Repository\AgentConversationRepository;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\FileService;
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
        private readonly AgentRecordAttachmentResolver $recordAttachmentResolver,
        private readonly AgentLowRiskFieldMatrix $lowRiskFieldMatrix,
        private readonly AgentContextPresenter $contextPresenter,
        private readonly AgentSessionPresenter $sessionPresenter,
        private readonly AgentProviderOptions $providerOptions,
        private readonly AgentConversationRepository $conversationRepository,
        private readonly AgentRecordLabeler $recordLabeler,
        private readonly AgentSettingsService $agentSettings,
        private readonly AgentCreditsStatus $creditsStatus,
        private readonly AgentConversationRecorder $conversationRecorder,
        private readonly AgentConversationSummarizer $conversationSummarizer,
        private readonly AgentWorkspaceTarget $workspaceTarget,
        private readonly FileService $fileService,
        private readonly ModuleTabUtility $moduleTabUtility,
        private readonly RecordAccessGate $recordAccessGate,
        private readonly UriBuilder $uriBuilder,
        private readonly ConnectionPool $connectionPool,
        private readonly AgentTurnRouter $turnRouter,
        private readonly AgentTargetPageResolver $targetPageResolver,
        private readonly AgentTranslator $translator,
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

    /**
     * Opens a conversation: the requested session (sessionUuid), a fresh one (fresh=1), or the
     * latest one of the configured scope for the current page / module.
     */
    public function conversationAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        if ($request->getMethod() === 'POST') {
            return $this->conversationSaveAction($request);
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
        }

        $query = $request->getQueryParams();
        $context = $this->resolveContext($request);
        $this->applyWorkspaceContext($user, (int) ($context['workspaceId'] ?? 0));
        $row = $this->conversationSession->resolve(
            $user,
            $context,
            trim((string) ($query['sessionUuid'] ?? '')),
            (string) ($query['fresh'] ?? '') === '1',
        );

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
            'disclosureDismissed' => $this->conversationSession->isDisclosureDismissed($user),
            'session' => $row !== null ? $this->sessionPresenter->summary($row, $context, $user) : null,
            'sessionList' => $this->sessionPresenter->listSettings(),
            'providers' => $this->providerOptions->options((int) ($context['pageId'] ?? 0), $user),
            'continueAfterConfirm' => $this->agentSettings->isContinueAfterConfirmEnabled(),
            'credits' => $this->creditsStatus->status(),
        ]);
    }

    /**
     * Stores the window's copy of the active conversation (draft decisions, readbacks).
     * Only for a session the user owns; turns are stored by the server itself.
     */
    public function conversationSaveAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
        }

        $body = $this->parseRequestBody($request);
        if (array_key_exists('disclosureDismissed', $body)) {
            $this->conversationSession->setDisclosureDismissed((bool) $body['disclosureDismissed'], $user);
        }

        // Messages are stored by the server only (turns and card actions); a messages
        // payload from older agent.js versions is ignored.
        return new JsonResponse(['ok' => true]);
    }

    /**
     * The user's conversations: filter=current (this page / module) or all.
     */
    public function sessionsAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }
        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
        }

        $query = $request->getQueryParams();
        $context = $this->resolveContext($request);
        $limit = max(1, min(50, (int) ($query['limit'] ?? 20)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $sessions = $this->sessionPresenter->list(
            $user,
            (string) ($query['filter'] ?? 'current') === 'all' ? 'all' : 'current',
            $context,
            $limit + 1,
            $offset,
        );

        return new JsonResponse([
            'ok' => true,
            'sessions' => array_slice($sessions, 0, $limit),
            'hasMore' => count($sessions) > $limit,
        ]);
    }

    public function sessionRenameAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }
        $user = $this->resolveBackendUser();
        $body = $this->parseRequestBody($request);
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($body['title'] ?? '')));
        if ($user === null || $title === '') {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.invalidPayload')], 400);
        }

        $ok = $this->conversationRepository->rename(trim((string) ($body['sessionUuid'] ?? '')), (int) ($user->user['uid'] ?? 0), $title);

        return new JsonResponse(['ok' => $ok], $ok ? 200 : 404);
    }

    public function sessionDeleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }
        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
        }
        $body = $this->parseRequestBody($request);
        $ok = $this->conversationRepository->softDelete(trim((string) ($body['sessionUuid'] ?? '')), (int) ($user->user['uid'] ?? 0));

        return new JsonResponse(['ok' => $ok], $ok ? 200 : 404);
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
            'label' => $this->translator->translate('agent.modal.settings'),
        ]);
    }

    public function applyDraftAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
        }

        $body = $this->parseRequestBody($request);
        $draftId = trim((string) ($body['draftId'] ?? ''));
        $keptFieldKeys = is_array($body['keptFieldKeys'] ?? null) ? array_values(array_map('strval', $body['keptFieldKeys'])) : [];
        $applyMode = trim((string) ($body['applyMode'] ?? 'all'));
        // A draft workspace by default, also when the client sends Live (agentWorkspaceMode).
        $workspaceId = $this->workspaceTarget->resolve((int) ($body['workspaceId'] ?? 0), $user);
        $correlationId = trim((string) ($body['correlationId'] ?? ''));
        $selections = is_array($body['selections'] ?? null) ? $body['selections'] : [];
        $edits = is_array($body['edits'] ?? null) ? $body['edits'] : [];
        $editedByEditor = ($body['editedByEditor'] ?? false) === true
            || ($body['editedByEditor'] ?? '') === '1'
            || ($body['editedByEditor'] ?? 0) === 1;

        if ($draftId === '') {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.missingDraftId')], 400);
        }

        $workspaceBlock = $this->governanceGuard->assertDraftApplyAllowed($user, $workspaceId);
        if ($workspaceBlock !== null) {
            return new JsonResponse(['ok' => false, 'message' => $workspaceBlock], 403);
        }

        $storedDraft = $this->draftSession->getDraft($draftId);
        if ($storedDraft === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.draftNotFound')], 404);
        }

        $flow = (string) ($storedDraft['flow'] ?? '');
        $isPreviewDraft = $flow === 'agent_preview';

        if (!$isPreviewDraft && $applyMode === 'safe') {
            $plan = ToolPlan::fromArray(is_array($storedDraft['plan'] ?? null) ? $storedDraft['plan'] : []);
            $keptFieldKeys = $this->lowRiskFieldMatrix->filterSafeFieldKeys($plan, $keptFieldKeys);
            if ($keptFieldKeys === []) {
                return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.draft.noSafeFields')], 400);
            }
        }

        if ($isPreviewDraft && $edits !== []) {
            $previewResult = is_array($storedDraft['previewResult'] ?? null) ? $storedDraft['previewResult'] : [];
            $previewTarget = is_array($previewResult['target'] ?? null) ? $previewResult['target'] : [];
            $table = (string) ($previewTarget['table'] ?? '');
            if ($table !== '' && !$this->recordAccessGate->canModifyTable($user, $table)) {
                return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
            }
            $editedByEditor = true;
        }

        if (!$this->applyWorkspaceContext($user, $workspaceId)) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.workspace.noAccess')], 403);
        }

        try {
            $result = $isPreviewDraft
                ? $this->writeService->applySuggestions(
                    $draftId,
                    $selections,
                    $this->normalizeStringMap($edits),
                    $editedByEditor,
                    $applyMode,
                    $correlationId !== '' ? $correlationId : null,
                )
                : $this->writeService->apply(
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
            [
                'draftId' => $draftId,
                'keptFieldKeys' => $keptFieldKeys,
                'selections' => $selections,
                'suggestionsApply' => $isPreviewDraft,
            ],
            true,
            (int) ($result['latencyMs'] ?? 0),
        );

        $user = $this->resolveBackendUser();
        $handoff = $user !== null
            ? $this->schedulerHandoff->buildHandoffForApplyResult($result, [], $user, $flow !== '' ? $flow : null)
            : null;

        $message = ($result['toolConfirmation'] ?? false) === true
            ? $this->translator->translate('agent.draft.toolApplied')
            : $this->translator->translate('agent.draft.applied', [
                (string) ($result['appliedCount'] ?? 0),
                (string) ($result['totalCount'] ?? 0),
            ]);
        if (($result['suggestionsApply'] ?? false) === true) {
            $message = $this->translator->translate('agent.suggestions.applied', [
                (string) ($result['appliedCount'] ?? 0),
            ]);
        }

        $labelledResult = $this->labelReadback($result);
        $links = $this->resultLinks($result, $storedDraft);
        if ($user !== null) {
            $this->recordInConversation($user, $body, fn(array $messages): array => $this->conversationRecorder->applied(
                $messages,
                $draftId,
                $labelledResult,
                [
                    'message' => $message,
                    'links' => $links,
                    'schedulerHandoff' => $handoff,
                    'keptFieldKeys' => $isPreviewDraft ? null : $keptFieldKeys,
                    'selections' => $isPreviewDraft ? $selections : null,
                    'edits' => $isPreviewDraft ? $this->normalizeStringMap($edits) : null,
                ],
            ));
        }

        return new JsonResponse([
            'ok' => true,
            'result' => $labelledResult,
            'message' => $message,
            'schedulerHandoff' => $handoff,
            'links' => $links,
        ]);
    }

    /**
     * Applies a card outcome to the stored conversation (the server is its only writer).
     * Failing to record never fails the action itself.
     *
     * @param array<string, mixed> $body request body with sessionUuid
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    private function recordInConversation(BackendUserAuthentication $user, array $body, callable $change): void
    {
        $sessionUuid = trim((string) ($body['sessionUuid'] ?? ''));
        if ($sessionUuid === '') {
            return;
        }
        try {
            if ($this->conversationSession->resolve($user, [], $sessionUuid) === null) {
                return;
            }
            $messages = $this->conversationSession->getMessages();
            $updated = $change($messages);
            if ($updated !== $messages) {
                $this->conversationSession->save($user, $updated, $this->conversationSession->getContext());
            }
        } catch (\Throwable) {
            // The change itself succeeded; the transcript catches up with the next turn.
        }
    }

    /**
     * Editor-facing names for the read-back records and fields.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function labelReadback(array $result): array
    {
        foreach (is_array($result['readback'] ?? null) ? $result['readback'] : [] as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $table = (string) ($entry['table'] ?? '');
            $uid = (int) ($entry['uid'] ?? 0);
            $result['readback'][$index]['recordLabel'] = $this->recordLabeler->recordLabel($table, $uid);
            $fieldLabels = [];
            foreach (array_keys(is_array($entry['values'] ?? null) ? $entry['values'] : []) as $field) {
                $fieldLabels[(string) $field] = $this->recordLabeler->fieldLabel($table, (string) $field);
            }
            $result['readback'][$index]['fieldLabels'] = $fieldLabels;
        }

        return $result;
    }

    /**
     * "Open page", "Edit", "View" for the records a confirmed change touched (max. 3 records).
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $storedDraft
     * @return list<array{record: string, links: list<array{kind: string, label: string, href: string}>}>
     */
    private function resultLinks(array $result, array $storedDraft): array
    {
        $records = [];
        foreach (is_array($result['readback'] ?? null) ? $result['readback'] : [] as $entry) {
            if (is_array($entry)) {
                $records[] = [(string) ($entry['table'] ?? ''), (int) ($entry['uid'] ?? 0)];
            }
        }
        $target = $storedDraft['previewResult']['target'] ?? null;
        if (is_array($target)) {
            $records[] = [(string) ($target['table'] ?? ''), (int) ($target['uid'] ?? 0)];
        }
        // Confirmed child tools (translate page, generate SEO, …): the page they worked on.
        if (($result['toolConfirmation'] ?? false) === true) {
            $arguments = is_array($storedDraft['arguments'] ?? null) ? $storedDraft['arguments'] : [];
            $pageId = (int) ($arguments['pageId'] ?? 0);
            if ($pageId > 0) {
                $records[] = ['pages', $pageId];
            }
        }
        $user = $this->resolveBackendUser();

        $out = [];
        $seen = [];
        foreach ($records as [$table, $uid]) {
            $key = $table . ':' . $uid;
            if ($table === '' || $uid <= 0 || isset($seen[$key]) || !$this->recordAccessGate->canSelectTable($user, $table)) {
                continue;
            }
            $seen[$key] = true;
            $links = $this->recordLabeler->links($table, $uid);
            if ($links !== []) {
                $out[] = ['record' => $this->recordLabeler->recordLabel($table, $uid), 'links' => $links];
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    public function uploadAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
        }

        $upload = $this->resolveUploadedFile($request);
        if ($upload === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.upload.missingFile')], 400);
        }

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.upload.failed')], 400);
        }

        $size = (int) $upload->getSize();
        if ($size <= 0) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.upload.empty')], 400);
        }
        if ($size > self::UPLOAD_MAX_BYTES) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.upload.tooLarge')], 400);
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
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.upload.invalidName')], 400);
        }

        $content = (string) $upload->getStream()->getContents();
        if ($content === '') {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.upload.empty')], 400);
        }

        try {
            $result = $this->fileService->uploadFile($storageUid, $directoryPath, $fileName, $content);
        } catch (\Throwable $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
        }

        $identifier = (string) ($result['identifier'] ?? '');
        if ($identifier === '') {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.upload.failed')], 500);
        }

        return new JsonResponse([
            'ok' => true,
            'file' => $result,
            'attachment' => $this->recordAttachmentResolver->formatFileAttachmentToken($storageUid, $identifier),
            'message' => $this->translator->translate('agent.upload.success', [$fileName]),
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
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.missingDraftId')], 400);
        }

        $this->draftSession->removeDraft($draftId);
        $user = $this->resolveBackendUser();
        if ($user !== null) {
            $this->recordInConversation($user, $body, fn(array $messages): array => $this->conversationRecorder->declined($messages, $draftId));
        }

        return new JsonResponse([
            'ok' => true,
            'message' => $this->translator->translate('agent.draft.discarded'),
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
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.missingDraftId')], 400);
        }

        $draft = $this->draftSession->getDraft($draftId);
        if ($draft === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.draftNotFound')], 404);
        }

        $this->draftSession->setDestructiveArmed($draftId, true);
        $user = $this->resolveBackendUser();
        if ($user !== null) {
            $this->recordInConversation($user, $body, fn(array $messages): array => $this->conversationRecorder->armed($messages, $draftId));
        }

        return new JsonResponse([
            'ok' => true,
            'destructiveArmed' => true,
            'message' => $this->translator->translate('agent.draft.destructiveArmed'),
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
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.missingChangeId')], 400);
        }

        try {
            $result = $this->undoService->undo($changeId);
        } catch (\Throwable $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
        }

        $message = $this->translator->translate('agent.draft.undone');
        $user = $this->resolveBackendUser();
        if ($user !== null) {
            $this->recordInConversation($user, $body, fn(array $messages): array => $this->conversationRecorder->undone($messages, $message));
        }

        return new JsonResponse([
            'ok' => true,
            'result' => $result,
            'message' => $message,
        ]);
    }

    /**
     * "Summarize conversation": stores a short summary; later turns replay it instead of the
     * older messages.
     */
    public function summarizeAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->denyUnlessAvailable()) {
            return $denied;
        }
        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
        }

        $body = $this->parseRequestBody($request);
        $sessionUuid = trim((string) ($body['sessionUuid'] ?? ''));
        if ($sessionUuid === '' || $this->conversationSession->resolve($user, [], $sessionUuid) === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.session.notFound')], 404);
        }
        $messages = $this->conversationSession->getMessages();
        if (!AgentConversationSummarizer::canSummarize($messages)) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.summary.tooShort')], 400);
        }

        $storedContext = $this->conversationSession->getContext();
        try {
            $summary = $this->conversationSummarizer->summarize(
                $messages,
                $this->conversationSession->providerIdentifier(),
                (int) ($storedContext['pageId'] ?? 0),
            );
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->translator->translate('agent.summary.failed', [$exception->getMessage()]),
            ], 502);
        }

        $this->conversationSession->save($user, [...$messages, $summary], $storedContext);

        return new JsonResponse([
            'ok' => true,
            'message' => $summary,
            'credits' => $this->creditsStatus->status(),
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
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.emptyMessage')], 400);
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
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
        $turn = $this->prepareTurn($request, $body, $message, $user, $correlationId);
        if ($turn instanceof JsonResponse) {
            return $turn;
        }

        $assistantMessages = $this->turnRouter->route(
            $turn['message'],
            $turn['turnContext'],
            $turn['body'],
            $user,
            $correlationId,
            $turn['history'],
        );

        $this->conversationSession->save($user, [...$turn['messages'], ...$assistantMessages], $turn['context'], $turn['provider']);

        return new JsonResponse([
            'ok' => true,
            'messages' => $assistantMessages,
            'context' => $turn['context'],
            'starters' => $this->buildStarters($turn['context']),
            'correlationId' => $correlationId,
            'session' => $this->activeSessionSummary($turn['context'], $user),
            'credits' => $this->creditsStatus->status(),
            'userMessage' => $turn['messages'][count($turn['messages']) - 1],
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
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.emptyMessage')], 400);
        }

        $user = $this->resolveBackendUser();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
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
        $turn = $this->prepareTurn($request, $body, $message, $user, $correlationId);
        if ($turn instanceof JsonResponse) {
            return $turn;
        }

        $streamBody = new PumpStream(function () use ($turn, $user, $correlationId): false {
            static $emitted = false;
            if ($emitted) {
                return false;
            }
            $emitted = true;

            $this->configureSseStream();

            try {
                $assistantMessages = $this->turnRouter->route(
                    $turn['message'],
                    $turn['turnContext'],
                    $turn['body'],
                    $user,
                    $correlationId,
                    $turn['history'],
                    function (string $event, array $payload): void {
                        $this->emitSseEvent($event, $payload);
                    },
                );

                $this->conversationSession->save($user, [...$turn['messages'], ...$assistantMessages], $turn['context'], $turn['provider']);

                $this->emitSseEvent('done', [
                    'ok' => true,
                    'messages' => $assistantMessages,
                    'context' => $turn['context'],
                    'starters' => $this->buildStarters($turn['context']),
                    'correlationId' => $correlationId,
                    'session' => $this->activeSessionSummary($turn['context'], $user),
                    'credits' => $this->creditsStatus->status(),
                    'userMessage' => $turn['messages'][count($turn['messages']) - 1],
                ]);
            } catch (\Throwable $exception) {
                $this->emitSseEvent('error', [
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ]);
            }

            // PumpStream forbids ''; false = EOF (SSE already flushed via emitSseEvent).
            return false;
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
     * @param array<string, mixed> $context
     * @return array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    private function buildStarters(array $context): array
    {
        return $this->starterBuilder->build($context);
    }

    /**
     * Opens the conversation of this turn, locks its provider and builds the history.
     *
     * The conversation is chosen with the context of the page shown (its home for a new
     * conversation); a page named in the message only changes the context of this turn.
     *
     * @param array<string, mixed> $body
     * @return array{message: string, context: array<string, mixed>, turnContext: array<string, mixed>, body: array<string, mixed>, history: list<array<string, mixed>>, messages: list<array<string, mixed>>, provider: string}|JsonResponse
     */
    private function prepareTurn(
        ServerRequestInterface $request,
        array $body,
        string $message,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array|JsonResponse {
        $context = $this->resolveContext($request, is_array($body['context'] ?? null) ? $body['context'] : []);
        $this->applyWorkspaceContext($user, (int) ($context['workspaceId'] ?? 0));
        $fresh = ($body['fresh'] ?? false) === true || (string) ($body['fresh'] ?? '') === '1';
        $this->conversationSession->resolve($user, $context, trim((string) ($body['sessionUuid'] ?? '')), $fresh);

        $locked = $this->conversationSession->providerIdentifier();
        $provider = $locked !== '' ? $locked : trim((string) ($body['provider'] ?? ''));
        $provider = $provider !== '' ? $provider : AgentProviderOptions::DEFAULT;
        if (!$this->providerOptions->isAllowed($provider, (int) ($context['pageId'] ?? 0), $user)) {
            return new JsonResponse([
                'ok' => true,
                'messages' => [[
                    'role' => 'assistant',
                    'content' => $this->translator->translate('agent.provider.notAllowed'),
                    'meta' => ['type' => 'governance_blocked', 'correlationId' => $correlationId],
                ]],
            ]);
        }
        $body['provider'] = $provider;

        $history = $this->conversationSession->getMessages();
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $continuation = is_array($body['continuation'] ?? null) ? $body['continuation'] : null;
        if ($continuation !== null) {
            $message = $this->continuationMessage($continuation, $history);
        }
        $messages = $history;
        $messages[] = [
            'role' => 'user',
            'content' => $message,
            'meta' => [
                'correlationId' => $correlationId,
                // Continuation after confirm / decline: sent to the model, not shown in the window.
                'hidden' => $continuation !== null,
                'type' => $continuation !== null ? 'continuation' : 'message',
                // Where the message was written; a reopened conversation shows and tells the model.
                'context' => [
                    'pageId' => (int) ($context['pageId'] ?? 0),
                    'pageTitle' => (string) ($details['page']['title'] ?? ''),
                    'module' => (string) ($context['module'] ?? ''),
                    'moduleLabel' => (string) ($details['module']['label'] ?? ''),
                ],
            ],
        ];

        return [
            'message' => $message,
            'context' => $context,
            'turnContext' => $continuation !== null ? $context : $this->applyTargetPageFromMessage($message, $context, $user),
            'body' => $body,
            'history' => $history,
            'messages' => $messages,
            'provider' => $provider,
        ];
    }

    /**
     * The instruction for the turn after the editor confirmed or declined a card (English, like
     * the system prompt; the reply language rule still applies).
     *
     * The records the change wrote (table, uid, title) come from the stored result, so a new
     * page's uid can be used right away (e.g. as pid of its content elements).
     *
     * @param array<mixed> $continuation {outcome: applied|declined, label: string, result: string}
     * @param list<array<string, mixed>> $history
     */
    private function continuationMessage(array $continuation, array $history = []): string
    {
        $label = mb_substr(trim((string) ($continuation['label'] ?? '')), 0, 120);
        $result = mb_substr(trim((string) ($continuation['result'] ?? '')), 0, 600);
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') === 'user') {
                break;
            }
            if (($meta['type'] ?? '') === 'readback_result') {
                $result = trim($result . AgentPromptBuilder::appliedRecordsNote($meta));
                break;
            }
        }
        if (($continuation['outcome'] ?? '') === 'declined') {
            return sprintf(
                '[The editor declined "%s". Nothing was written.] Do not repeat it. If other steps of my request remain, continue with them;'
                . ' otherwise ask in one short sentence what to do instead.',
                $label,
            );
        }

        return sprintf(
            '[The editor confirmed "%s" and it was applied.%s] Continue with the remaining steps of my request.'
            . ' If nothing is left, confirm in one short sentence without calling tools: the result above is final,'
            . ' so do not check it again or list what else you can do.',
            $label,
            $result !== '' ? ' Result: ' . $result : '',
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function activeSessionSummary(array $context, BackendUserAuthentication $user): ?array
    {
        $row = $this->conversationSession->current();

        return $row !== null ? $this->sessionPresenter->summary($row, $context, $user) : null;
    }

    /**
     * A page named in the message ("page 12", "#12", a title) becomes the page of this turn.
     *
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

        return $this->contextPresenter->present([
            'pageId' => $targetPageId,
            'module' => (string) ($context['module'] ?? ''),
            'workspaceId' => (int) ($context['workspaceId'] ?? 0),
        ], $user);
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

        $moduleLabel = (string) ($context['details']['module']['label'] ?? '');

        return [
            'page' => $page,
            'module' => $moduleLabel !== '' ? $moduleLabel : $module,
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
            'storageUid' => max(0, (int) ($clientContext['storageUid'] ?? 0)),
            'folderIdentifier' => trim((string) ($clientContext['folderIdentifier'] ?? '')),
        ], $clientContext);

        return $this->contextPresenter->present($merged, $this->resolveBackendUser());
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

        return new JsonResponse(['ok' => false, 'message' => $this->translator->translate('agent.error.forbidden')], 403);
    }

    /**
     * @param array<mixed> $map
     * @return array<string, string>
     */
    private function normalizeStringMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '' || !is_scalar($value)) {
                continue;
            }
            $normalized[$key] = (string) $value;
        }

        return $normalized;
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

    /**
     * Switches the workspace for this request only (setWorkspace() would also change the
     * editor's backend workspace). False when the editor may not use that workspace.
     */
    private function applyWorkspaceContext(BackendUserAuthentication $user, int $workspaceId): bool
    {
        if ($workspaceId <= 0 || !AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            return true;
        }

        return $user->setTemporaryWorkspace($workspaceId);
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

}
