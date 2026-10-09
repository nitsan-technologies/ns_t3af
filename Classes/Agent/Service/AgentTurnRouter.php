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

    /**
     * Slash names editors type that are not the MCP tool name.
     *
     * @var array<string, string>
     */
    private const SLASH_TOOL_ALIASES = [
        'folder_rename' => 'directory_rename',
    ];

    private const SEO_TOOL = 't3ai_generate_all_seo';

    private const FILE_METADATA_TOOL = 't3aa_update_file_metadata';

    public function __construct(
        private AgentMessageParser $messageParser,
        private AgentSlashArgumentBinder $slashArgumentBinder,
        private AgentRecordAttachmentResolver $recordAttachmentResolver,
        private AgentToolTurnExecutorInterface $toolTurnProcessor,
        private AgentTurnRunnerInterface $turnOrchestrator,
        private PermittedActionProvider $permittedActionProvider,
        private FileService $fileService,
        private AgentTranslator $translator,
        private AgentTargetPageResolver $targetPageResolver,
        private ?AgentRequestedFiles $requestedFiles = null,
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
        $slashRemainder = '';

        $typedSlash = false;
        if ($selectedTool === '') {
            $parsed = $this->messageParser->extractSlashCommand($message);
            $selectedTool = $parsed['name'];
            $typedSlash = $selectedTool !== '';
            if ($toolArguments === [] && $parsed['arguments'] !== []) {
                $toolArguments = $parsed['arguments'];
            }
            $slashRemainder = trim((string) ($parsed['remainder'] ?? ''));
        }

        if ($typedSlash && $selectedTool !== '') {
            $selectedTool = self::canonicalSlashTool($selectedTool);
        }

        $recordAttachments = $this->recordAttachmentResolver->extractAttachments($message);
        $fileAttachments = $this->recordAttachmentResolver->extractFileAttachments($message);
        // "/page tree" is not a command when no tool has that name: treat the text as a normal
        // request instead of answering "Unknown tool".
        if (
            $typedSlash
            && $selectedTool !== self::LEGACY_SEO_ACTION
            && $selectedTool !== self::LEGACY_FILE_METADATA_ACTION
            && !$this->isKnownTool($selectedTool)
        ) {
            $message = ltrim($this->messageParser->stripComposerTokens($message), '/ ');
            $selectedTool = '';
            $toolArguments = [];
            $slashRemainder = '';
        }

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

        if ($selectedTool !== '' && $slashRemainder !== '') {
            $toolArguments = $this->slashArgumentBinder->bindForTool(
                $selectedTool,
                $toolArguments,
                $slashRemainder,
            );
        }

        $body['arguments'] = $toolArguments;

        if ($selectedTool !== '') {
            $messages = $this->finalizeStructuralMessages(
                [$this->executeTool($selectedTool, $context, $body, $user, $correlationId)],
                $correlationId,
            );
            $this->emitMessages($emitEvent, $messages);

            return $messages;
        }

        $followUp = $this->messageParser->stripComposerTokens($message);
        if ($followUp !== '') {
            $followUp = $this->messageParser->describeComposerTokens($message);
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

        if ($followUp === '' && $attachmentMessages !== []) {
            $attachmentMessages = $this->finalizeStructuralMessages($attachmentMessages, $correlationId);
            $this->emitMessages($emitEvent, $attachmentMessages);

            return $attachmentMessages;
        }

        $nlMessage = $followUp !== '' ? $followUp : $message;
        $namedPage = $this->applyNamedPage($nlMessage, $historyMessages, $context, $user, $correlationId);
        if ($namedPage['messages'] !== null) {
            $this->emitMessages($emitEvent, $namedPage['messages']);

            return $attachmentMessages === []
                ? $namedPage['messages']
                : array_merge($attachmentMessages, $namedPage['messages']);
        }
        $context = $namedPage['context'];

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

        // Files the request names that cannot be attached are reported before the model runs; the
        // checklist then closes the attach step instead of asking the editor to "continue".
        $unavailableFiles = [];
        $isContinuation = is_array($body['continuation'] ?? null) || str_starts_with(trim($message), '[The editor ');
        if (!$isContinuation && $this->requestedFiles !== null) {
            $notice = $this->requestedFiles->messageFor($message, $this->translator, $correlationId);
            if ($notice === null && AgentRequestedFiles::asksForGeneratedImage($message) && !$this->canGenerateImages()) {
                $notice = $this->requestedFiles->generationUnavailableMessage($message, $this->translator, $correlationId);
            }
            if ($notice !== null) {
                $unavailableFiles = [$notice];
                $this->emitMessages($emitEvent, $unavailableFiles);
                $historyMessages[] = $notice;
            }
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

        return array_merge($unavailableFiles, $orchestratorResult['messages']);
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
     * A named page that does not exist is not replaced by the page on screen.
     * The editor is asked which page to use. A later "the first one" locks that page.
     *
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed> $context
     * @return array{context: array<string, mixed>, messages: list<array{role: string, content: string, meta: array<string, mixed>}>|null}
     */
    private function applyNamedPage(
        string $message,
        array $history,
        array $context,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        $choice = AgentTargetPageResolver::pageIdFromChoice($message, $history);
        if ($choice > 0) {
            return ['context' => $this->lockPage($context, $choice, self::titleFromChoice($message, $history)), 'messages' => null];
        }
        if (AgentTargetPageResolver::isCreatePageRequest($message)) {
            return ['context' => $context, 'messages' => null];
        }

        $title = AgentTargetPageResolver::namedPageTitle($message);
        if ($title === null) {
            return ['context' => $context, 'messages' => null];
        }

        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $openPage = is_array($details['page'] ?? null) ? $details['page'] : [];
        $openTitle = trim((string) ($openPage['title'] ?? ''));
        if ($openTitle !== '' && mb_strtolower($openTitle) === mb_strtolower($title)) {
            return ['context' => $context, 'messages' => null];
        }

        $match = $this->targetPageResolver->matchingPage($title, $user);
        if ($match !== null) {
            return ['context' => $this->lockPage($context, $match['uid'], $match['title']), 'messages' => null];
        }

        $options = $this->targetPageResolver->similarPageLabels($title, $user);

        return [
            'context' => $context,
            'messages' => [[
                'role' => 'assistant',
                'content' => $this->missingPageQuestion($title),
                'meta' => [
                    'type' => 'clarification',
                    'tool' => 'ask_clarification',
                    'options' => $options,
                    'missingPage' => true,
                    'missingPageName' => $title,
                    'correlationId' => $correlationId,
                    'orchestratorPause' => true,
                ],
            ]],
        ];
    }

    private function missingPageQuestion(string $title): string
    {
        try {
            $question = $this->translator->translate('agent.page.notFound', [$title]);
        } catch (\Throwable) {
            $question = '';
        }
        if ($question === '' || $question === 'agent.page.notFound') {
            return sprintf('I can\'t find a page called "%s". Which page should I use?', $title);
        }

        return $question;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private static function titleFromChoice(string $message, array $history): string
    {
        $uid = AgentTargetPageResolver::pageIdFromChoice($message, $history);
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            $options = is_array($meta['options'] ?? null) ? $meta['options'] : [];
            foreach ($options as $option) {
                if (!is_string($option) || !str_ends_with($option, '[' . $uid . ']')) {
                    continue;
                }

                return trim((string) preg_replace('/\s*\[\d+\]\s*$/', '', $option));
            }
        }

        return trim((string) preg_replace('/\s*\[\d+\]\s*$/', '', $message));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function lockPage(array $context, int $pageId, string $title): array
    {
        $context['pageId'] = $pageId;
        $context['lockedPageId'] = $pageId;
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $page = is_array($details['page'] ?? null) ? $details['page'] : [];
        $page['uid'] = $pageId;
        if ($title !== '') {
            $page['title'] = $title;
        }
        $details['page'] = $page;
        $context['details'] = $details;

        return $context;
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
     * Whether a tool with this name is in the catalog. When the catalog cannot be built, the
     * name counts as known, so a typed command keeps its old structural handling.
     */
    private static function canonicalSlashTool(string $toolName): string
    {
        return self::SLASH_TOOL_ALIASES[strtolower($toolName)] ?? $toolName;
    }

    private function canGenerateImages(): bool
    {
        try {
            $executable = $this->permittedActionProvider->buildCatalog()['executable'];
        } catch (\Throwable) {
            return true;
        }

        return in_array(AgentRequestedFiles::GENERATION_TOOL, array_map(static fn(array $tool): string => (string) ($tool['name'] ?? ''), $executable), true);
    }

    private function isKnownTool(string $toolName): bool
    {
        try {
            return $this->findTool($this->permittedActionProvider->buildCatalog(), $toolName) !== null;
        } catch (\Throwable) {
            return true;
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
     * Slash / @ / starter tool turns have no model closing line. Mirror NL turns: keep the
     * tool_result (collapsible step) and append an nl_reply so the editor always sees the answer.
     *
     * @param list<array{role: string, content: string, meta: array<string, mixed>}> $messages
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function finalizeStructuralMessages(array $messages, string $correlationId): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = $message;
            $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
            if (($meta['type'] ?? '') !== 'tool_result') {
                continue;
            }
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $replyMeta = [
                'type' => 'nl_reply',
                'correlationId' => $correlationId,
                'fromToolResult' => true,
            ];
            $previews = is_array($meta['previews'] ?? null) ? $meta['previews'] : [];
            if ($previews !== []) {
                $replyMeta['previews'] = $previews;
            }
            $out[] = [
                'role' => 'assistant',
                'content' => $content,
                'meta' => $replyMeta,
            ];
        }

        return $out;
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
