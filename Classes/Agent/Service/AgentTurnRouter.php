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
use NITSAN\NsT3AF\Agent\Contract\AgentTurnRunnerInterface;
use NITSAN\NsT3AF\Mcp\Service\FileService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Single entry for agent turn routing: structural (slash / @ / UI tool) vs free-text NL.
 *
 * Meaning for free-text lives only in the LLM (AgentRunner, with find_tools for tool discovery).
 *
 * @internal
 */
final readonly class AgentTurnRouter
{
    private const LEGACY_SEO_ACTION = 'generate_seo_metadata';

    private const LEGACY_FILE_METADATA_ACTION = 'generate_file_metadata';

    private const SEO_TOOL = 't3ai_generate_all_seo';

    private const FILE_METADATA_TOOL = 't3aa_update_file_metadata';

    public function __construct(
        private AgentMessageParser $messageParser,
        private AgentRecordAttachmentResolver $recordAttachmentResolver,
        private AgentToolTurnExecutorInterface $toolTurnProcessor,
        private AgentTurnRunnerInterface $turnOrchestrator,
        private PermittedActionProvider $permittedActionProvider,
        private FileService $fileService,
        private AgentTranslator $translator,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array<string, mixed>> $historyMessages Conversation including the current user message
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    public function route(
        string $message,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $historyMessages = [],
        ?callable $emitEvent = null,
    ): array {
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

        [$selectedTool, $toolArguments, $starterAction] = $this->normalizeLegacyStructuralActions(
            $selectedTool,
            $toolArguments,
            $starterAction,
            $context,
            $fileAttachments,
        );

        $body['arguments'] = $toolArguments;

        if ($selectedTool !== '') {
            $messages = [$this->executeTool($selectedTool, $context, $body, $user, $correlationId)];
            $this->emitMessages($emitEvent, $messages);

            return $messages;
        }

        $followUp = $this->messageParser->stripComposerTokens($message);
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

        if ($followUp === '' && $attachmentMessages !== []) {
            $this->emitMessages($emitEvent, $attachmentMessages);

            return $attachmentMessages;
        }

        if ($followUp !== '' && $attachmentMessages !== []) {
            $this->emitMessages($emitEvent, $attachmentMessages);
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
     * Map retired NL flow action ids to real MCP tools (structural path only).
     *
     * @param array<string, mixed> $toolArguments
     * @param array<string, mixed> $context
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function normalizeLegacyStructuralActions(
        string $selectedTool,
        array $toolArguments,
        string $starterAction,
        array $context,
        array $fileAttachments,
    ): array {
        if ($starterAction === '' && $selectedTool === self::LEGACY_SEO_ACTION) {
            $starterAction = self::LEGACY_SEO_ACTION;
            $selectedTool = '';
        }
        if ($starterAction === '' && $selectedTool === self::LEGACY_FILE_METADATA_ACTION) {
            $starterAction = self::LEGACY_FILE_METADATA_ACTION;
            $selectedTool = '';
        }

        if ($starterAction === self::LEGACY_SEO_ACTION || $selectedTool === self::LEGACY_SEO_ACTION) {
            $pageId = (int) ($context['pageId'] ?? 0);
            $args = $toolArguments;
            if ($pageId > 0) {
                $args['pageId'] ??= $pageId;
                $args['pid'] ??= $pageId;
                $args['uid'] ??= $pageId;
            }

            return [self::SEO_TOOL, $args, ''];
        }

        if ($starterAction === self::LEGACY_FILE_METADATA_ACTION || $selectedTool === self::LEGACY_FILE_METADATA_ACTION) {
            $args = $toolArguments;
            $fileUid = (int) ($args['fileUid'] ?? $args['uid'] ?? 0);
            if ($fileUid <= 0) {
                foreach ($fileAttachments as $attachment) {
                    $fileUid = $this->fileService->resolveFileUid(
                        (int) ($attachment['storageUid'] ?? 0),
                        (string) ($attachment['identifier'] ?? ''),
                    );
                    if ($fileUid > 0) {
                        break;
                    }
                }
            }
            if ($fileUid > 0) {
                $args['fileUid'] = $fileUid;
            }

            return [self::FILE_METADATA_TOOL, $args, ''];
        }

        return [$selectedTool, $toolArguments, $starterAction];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function executeTool(
        string $toolName,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        return $this->toolTurnProcessor->execute($toolName, $context, $body, $user, $correlationId);
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

        $catalog = $this->permittedActionProvider->buildCatalog();
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
                    'content' => $this->translator->translate('agent.turn.unknownAttachment', [$table, (string) $uid]),
                    'meta' => [
                        'type' => 'error',
                        'correlationId' => $correlationId,
                        'attachedRecord' => $attachment,
                    ],
                ];
                continue;
            }

            $body['arguments'] = $invocation['arguments'];
            $message = $this->executeTool($invocation['tool'], $context, $body, $user, $correlationId);
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

        $catalog = $this->permittedActionProvider->buildCatalog();
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
                    'content' => $this->translator->translate('agent.turn.unknownFileAttachment', [$identifier]),
                    'meta' => [
                        'type' => 'error',
                        'correlationId' => $correlationId,
                        'attachedFile' => $attachment,
                    ],
                ];
                continue;
            }

            $body['arguments'] = $invocation['arguments'];
            $message = $this->executeTool($invocation['tool'], $context, $body, $user, $correlationId);
            $message['meta']['attachedFile'] = $attachment;
            $message['meta']['triggeredByAttachment'] = true;
            $messages[] = $message;
        }

        return $messages;
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
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     * @param list<array{role: string, content: string, meta: array<string, mixed>}> $messages
     */
    private function emitMessages(?callable $emitEvent, array $messages): void
    {
        if ($emitEvent === null) {
            return;
        }
        foreach ($messages as $message) {
            $emitEvent('message', ['message' => $message]);
        }
    }
}
