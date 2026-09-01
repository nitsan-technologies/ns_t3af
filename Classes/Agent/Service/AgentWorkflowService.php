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
use NITSAN\NsT3AF\Mcp\Service\FileService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Data-driven NL workflow dispatcher for all ns_t3* satellite and core tools.
 *
 * @internal
 */
final readonly class AgentWorkflowService
{
    public function __construct(
        private AgentNlIntentResolver $nlIntentResolver,
        private AgentSeoMetadataFlow $seoMetadataFlow,
        private AgentCompoundFlowService $compoundFlowService,
        private AgentToolRetriever $toolRetriever,
        private AgentToolTurnProcessor $toolTurnProcessor,
        private PermittedActionProvider $permittedActionProvider,
        private AgentMessageParser $messageParser,
        private FileService $fileService,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array{table: string, uid: int}> $recordAttachments
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>|null
     */
    public function tryExecute(
        string $message,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $recordAttachments = [],
        array $fileAttachments = [],
    ): ?array {
        $stripped = $this->messageParser->stripComposerTokens($message);
        $pageId = (int) ($context['pageId'] ?? 0);
        $catalog = $this->permittedActionProvider->buildCatalog();
        $executable = $catalog['executable'];

        $compoundSteps = $this->nlIntentResolver->resolveCompoundSteps($message);
        if ($this->nlIntentResolver->isCompoundTranslateSeoFlow($compoundSteps)) {
            return $this->compoundFlowService->execute($compoundSteps, $pageId, $correlationId, $user);
        }

        $starter = $this->resolveStarterAction($message, $fileAttachments);
        if ($starter === 'generate_seo_metadata') {
            return $this->seoMetadataFlow->execute($pageId, $correlationId);
        }

        $workflowId = $this->matchWorkflow($stripped, $context, $fileAttachments, $recordAttachments);
        if ($workflowId === null) {
            return null;
        }

        $result = $this->executeWorkflow(
            $workflowId,
            $stripped,
            $context,
            $body,
            $user,
            $correlationId,
            $executable,
            $fileAttachments,
            $recordAttachments,
        );

        return $result === [] ? null : $result;
    }

    /**
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     */
    public function resolveStarterAction(string $message, array $fileAttachments = []): string
    {
        if ($fileAttachments !== [] && $this->looksLikeFileMetadataWrite($this->messageParser->stripComposerTokens($message))) {
            return 'generate_file_metadata';
        }

        return $this->nlIntentResolver->resolveStarterAction($message);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @param list<array{table: string, uid: int}> $recordAttachments
     */
    private function matchWorkflow(
        string $message,
        array $context,
        array $fileAttachments,
        array $recordAttachments,
    ): ?string {
        $lower = strtolower(trim($message));
        if ($lower === '') {
            return null;
        }

        if ($fileAttachments !== [] && $this->looksLikeFileMetadataWrite($message)) {
            return 'generate_file_metadata';
        }

        if ($fileAttachments !== [] && $this->looksLikeFileMetadataRead($message)) {
            return 'read_file_metadata';
        }

        if ($recordAttachments !== [] && preg_match('/\b(update|write|change|edit|set)\b/i', $message)) {
            return 'write_record_attachment';
        }

        if ($this->looksLikeFileMetadataWrite($message) && $this->isFileModule($context)) {
            return 'generate_file_metadata';
        }

        if (preg_match('/\b(missing|without)\b.*\balt\b/i', $message)
            || preg_match('/\balt\b.*\b(missing|empty)\b/i', $message)) {
            return 'list_missing_alt_text';
        }

        if (preg_match('/\b(page\s*speed|pagespeed|core web vitals|performance score)\b/i', $message)) {
            return 'page_speed_check';
        }

        if (preg_match('/\b(summarize|summary)\b.*\b(content|page|element|text)\b/i', $message)
            || preg_match('/\b(content|page|element)\b.*\b(summarize|summary)\b/i', $message)) {
            return 'summarize_content';
        }

        if (preg_match('/\btranslate\b/i', $message) && preg_match('/\bnews\b/i', $message)) {
            return 'translate_news';
        }

        if (preg_match('/\b(translate|translation|localize|localise)\b/i', $message) && !preg_match('/\bseo\b/i', $message)) {
            return 'translate_page';
        }

        if (preg_match('/\b(generate|create|write|optimize|optimise|improve|apply)\b.*\b(ai\s+)?seo\b/i', $message)
            && !preg_match('/\b(file|image|alt)\b/i', $message)) {
            return 'generate_ai_seo';
        }

        if (preg_match('/\b(schema markup|structured data|json-ld)\b/i', $message)) {
            return 'apply_schema_markup';
        }

        if (preg_match('/\b(analyze|analysis|improve)\b.*\bcontent\b/i', $message)) {
            return 'apply_content_analysis';
        }

        if (preg_match('/\b(queue|schedule)\b.*\b(seo|translation|translate)\b/i', $message)) {
            return preg_match('/\btranslation|translate\b/i', $message)
                ? 'mass_translation_queue_add'
                : 'mass_seo_queue_add';
        }

        if (preg_match('/\b(seo queue|translation queue)\b.*\b(list|status|show)\b/i', $message)
            || preg_match('/\b(list|show)\b.*\b(seo queue|translation queue)\b/i', $message)) {
            return preg_match('/\btranslation\b/i', $message)
                ? 'mass_translation_queue_list'
                : 'mass_seo_queue_list';
        }

        if (preg_match('/\b(sync|synchroni[sz]e|reindex)\b.*\b(datasource|data source|chatbot source)\b/i', $message)) {
            return 'sync_datasource';
        }

        if (preg_match('/\b(training queue|datasource queue|failed queue)\b/i', $message)) {
            return 'training_queue_status';
        }

        if (preg_match('/\b(usage analytics|chatbot usage|token usage)\b/i', $message)) {
            return 'usage_analytics';
        }

        if (preg_match('/\b(publish|push live)\b.*\bworkspace\b/i', $message)
            || preg_match('/\bworkspace\b.*\bpublish\b/i', $message)) {
            return 'workspace_publish';
        }

        if (preg_match('/\b(discard|drop)\b.*\bworkspace\b/i', $message)
            || preg_match('/\bworkspace\b.*\b(discard|drop)\b/i', $message)) {
            return 'workspace_discard';
        }

        if (preg_match('/\b(create|add)\b.*\bredirect\b/i', $message)) {
            return 'redirect_create';
        }

        if (preg_match('/\b(clear|flush)\b.*\b(cache)\b/i', $message)) {
            return 'cache_clear';
        }

        if (preg_match('/\b(chatbot settings|chatbot config)\b/i', $message)
            && preg_match('/\b(update|change|set|configure)\b/i', $message)) {
            return 'chatbot_settings';
        }

        if (preg_match('/\b(search settings|search config)\b/i', $message)) {
            return 'search_settings';
        }

        if (preg_match('/\b(predefined questions|search questions)\b/i', $message)) {
            return 'list_search_questions';
        }

        $auto = $this->toolRetriever->buildAutoInvocation($message, $context, $this->permittedActionProvider->buildCatalog()['executable']);
        if ($auto !== null) {
            return 'auto_invoke';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array<string, mixed>> $executable
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @param list<array{table: string, uid: int}> $recordAttachments
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function executeWorkflow(
        string $workflowId,
        string $message,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $executable,
        array $fileAttachments,
        array $recordAttachments,
    ): array {
        if ($workflowId === 'auto_invoke') {
            $auto = $this->toolRetriever->buildAutoInvocation($message, $context, $executable);
            if ($auto === null) {
                return [];
            }

            return $this->runWriteDraft($auto['tool'], $auto['arguments'], $context, $body, $user, $correlationId, $workflowId);
        }

        return match ($workflowId) {
            'generate_file_metadata' => $this->executeFileMetadataWrite(
                $context,
                $body,
                $user,
                $correlationId,
                $fileAttachments,
            ),
            'read_file_metadata' => $this->executeFileMetadataRead($context, $body, $user, $correlationId, $fileAttachments),
            'write_record_attachment' => $this->executeRecordAttachmentWrite(
                $context,
                $body,
                $user,
                $correlationId,
                $recordAttachments,
                $message,
            ),
            'list_missing_alt_text' => $this->runReadTool(
                't3aa_list_files_missing_alt_text',
                ['storageUid' => (int) ($context['storageUid'] ?? 0)],
                $context,
                $body,
                $user,
                $correlationId,
            ),
            'page_speed_check' => $this->runReadTool(
                't3aa_get_page_speed',
                ['pageId' => (int) ($context['pageId'] ?? 0)],
                $context,
                $body,
                $user,
                $correlationId,
            ),
            'summarize_content' => $this->runWriteDraft(
                't3aa_summarize_content',
                ['pageId' => (int) ($context['pageId'] ?? 0)],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'translate_page' => $this->runWriteDraft(
                't3ai_translate_content',
                ['pageId' => (int) ($context['pageId'] ?? 0)],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'translate_news' => $this->runWriteDraft('t3ai_translate_news', [], $context, $body, $user, $correlationId, $workflowId),
            'generate_ai_seo' => $this->runWriteDraft(
                't3ai_generate_all_seo',
                ['pageId' => (int) ($context['pageId'] ?? 0)],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'apply_schema_markup' => $this->runWriteDraft(
                't3ai_apply_schema_markup',
                ['pageId' => (int) ($context['pageId'] ?? 0)],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'apply_content_analysis' => $this->runWriteDraft(
                't3ai_apply_content_analysis',
                ['pageId' => (int) ($context['pageId'] ?? 0)],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'mass_seo_queue_add' => $this->runWriteDraft(
                't3ai_mass_seo_queue_add',
                ['pageId' => (int) ($context['pageId'] ?? 0), 'recursive' => true],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'mass_translation_queue_add' => $this->runWriteDraft(
                't3ai_mass_translation_queue_add',
                ['pageId' => (int) ($context['pageId'] ?? 0), 'recursive' => true],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'mass_seo_queue_list' => $this->runReadTool('t3ai_mass_seo_queue_list', [], $context, $body, $user, $correlationId),
            'mass_translation_queue_list' => $this->runReadTool('t3ai_mass_translation_queue_list', [], $context, $body, $user, $correlationId),
            'sync_datasource' => $this->runWriteDraft(
                't3cs_sync_datasource',
                ['rootPageId' => (int) ($context['pageId'] ?? 0), 'scope' => 'all', 'mode' => 'request'],
                $context,
                $body,
                $user,
                $correlationId,
                $workflowId,
            ),
            'training_queue_status' => $this->runReadTool('t3cs_training_summary', [], $context, $body, $user, $correlationId),
            'usage_analytics' => $this->runReadTool('t3cs_usage_analytics_summary', [], $context, $body, $user, $correlationId),
            'workspace_publish' => $this->runWriteDraft('workspace_publish', [], $context, $body, $user, $correlationId, $workflowId),
            'workspace_discard' => $this->runWriteDraft('workspace_discard', [], $context, $body, $user, $correlationId, $workflowId),
            'redirect_create' => $this->runWriteDraft('redirect_create', ['pid' => (int) ($context['pageId'] ?? 0)], $context, $body, $user, $correlationId, $workflowId),
            'cache_clear' => $this->runWriteDraft('cache_clear', ['groups' => 'pages'], $context, $body, $user, $correlationId, $workflowId),
            'chatbot_settings' => $this->runWriteDraft('t3ac_chatbot_settings', [], $context, $body, $user, $correlationId, $workflowId),
            'search_settings' => $this->runReadTool('t3as_search_settings', [], $context, $body, $user, $correlationId),
            'list_search_questions' => $this->runReadTool('t3as_list_predefined_questions', [], $context, $body, $user, $correlationId),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function executeFileMetadataWrite(
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $fileAttachments,
    ): array {
        $messages = [];
        $fileUid = 0;

        foreach ($fileAttachments as $attachment) {
            $fileUid = $this->resolveFileUid((int) ($attachment['storageUid'] ?? 0), (string) ($attachment['identifier'] ?? ''));
            if ($fileUid <= 0) {
                continue;
            }

            $readBody = $body;
            $readBody['arguments'] = [
                'storageUid' => (int) $attachment['storageUid'],
                'fileIdentifier' => (string) $attachment['identifier'],
            ];
            $readMessage = $this->toolTurnProcessor->execute('file_get_info', $context, $readBody, $user, $correlationId);
            $readMessage['meta']['workflow'] = 'generate_file_metadata';
            $messages[] = $readMessage;
            break;
        }

        if ($fileUid <= 0) {
            return [[
                'role' => 'assistant',
                'content' => $this->translate('agent.workflow.fileMetadataNeedsFile'),
                'meta' => ['type' => 'info', 'correlationId' => $correlationId, 'workflow' => 'generate_file_metadata'],
            ]];
        }

        if (!$this->toolIsExecutable('t3aa_update_file_metadata')) {
            return [[
                'role' => 'assistant',
                'content' => $this->translate('agent.turn.planUnsupported', ['t3aa_update_file_metadata']),
                'meta' => ['type' => 'error', 'correlationId' => $correlationId, 'workflow' => 'generate_file_metadata'],
            ]];
        }

        $draft = $this->runWriteDraft(
            't3aa_update_file_metadata',
            ['fileUid' => $fileUid],
            $context,
            $body,
            $user,
            $correlationId,
            'generate_file_metadata',
        );
        if ($draft !== []) {
            $draft[0]['meta']['workflow'] = 'generate_file_metadata';
        }

        return array_merge($messages, $draft);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array{storageUid: int, identifier: string}> $fileAttachments
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function executeFileMetadataRead(
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $fileAttachments,
    ): array {
        $fileUid = 0;
        foreach ($fileAttachments as $attachment) {
            $fileUid = $this->resolveFileUid((int) ($attachment['storageUid'] ?? 0), (string) ($attachment['identifier'] ?? ''));
            if ($fileUid > 0) {
                break;
            }
        }

        if ($fileUid <= 0 || !$this->toolIsExecutable('t3aa_get_file_metadata')) {
            return [];
        }

        return $this->runReadTool('t3aa_get_file_metadata', ['fileUid' => $fileUid], $context, $body, $user, $correlationId);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @param list<array{table: string, uid: int}> $recordAttachments
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function executeRecordAttachmentWrite(
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        array $recordAttachments,
        string $message,
    ): array {
        if (!$this->toolIsExecutable('write_table')) {
            return [];
        }

        $attachment = $recordAttachments[0] ?? null;
        if ($attachment === null) {
            return [];
        }

        $table = (string) ($attachment['table'] ?? '');
        $uid = (int) ($attachment['uid'] ?? 0);
        if ($table === '' || $uid <= 0) {
            return [];
        }

        return $this->runWriteDraft(
            'write_table',
            [
                'action' => 'update',
                'tableName' => $table,
                'uid' => $uid,
                'data' => '{}',
            ],
            $context,
            $body,
            $user,
            $correlationId,
            'write_record_attachment',
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function runWriteDraft(
        string $toolName,
        array $arguments,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
        string $workflowId,
    ): array {
        if (!$this->toolIsExecutable($toolName)) {
            return [[
                'role' => 'assistant',
                'content' => $this->translate('agent.turn.planUnsupported', [$toolName]),
                'meta' => ['type' => 'error', 'tool' => $toolName, 'workflow' => $workflowId, 'correlationId' => $correlationId],
            ]];
        }

        $toolBody = $body;
        $toolBody['arguments'] = $arguments;
        $message = $this->toolTurnProcessor->execute($toolName, $context, $toolBody, $user, $correlationId);
        $message['meta']['workflow'] = $workflowId;

        return [$message];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     * @return list<array{role: string, content: string, meta: array<string, mixed>}>
     */
    private function runReadTool(
        string $toolName,
        array $arguments,
        array $context,
        array $body,
        BackendUserAuthentication $user,
        string $correlationId,
    ): array {
        if (!$this->toolIsExecutable($toolName)) {
            return [];
        }

        $toolBody = $body;
        $toolBody['arguments'] = $arguments;
        $message = $this->toolTurnProcessor->execute($toolName, $context, $toolBody, $user, $correlationId);

        return [$message];
    }

    private function looksLikeFileMetadataWrite(string $message): bool
    {
        if (preg_match('/\bseo\b/i', $message) && !preg_match('/\b(file|image|alt)\b/i', $message)) {
            return false;
        }

        return preg_match(
            '/\b(write|generate|create|update|add|fill|set|improve|fix)\b.*\b(alt|metadata|meta data|caption|title|description|file meta)\b/i',
            $message,
        ) === 1
            || preg_match(
                '/\b(alt|metadata|meta data|caption)\b.*\b(write|generate|create|update|add|fill|set|for)\b/i',
                $message,
            ) === 1;
    }

    private function looksLikeFileMetadataRead(string $message): bool
    {
        return preg_match('/\b(show|read|get|what|current)\b.*\b(alt|metadata|meta data|caption)\b/i', $message) === 1;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isFileModule(array $context): bool
    {
        $module = strtolower((string) ($context['module'] ?? ''));

        return str_starts_with($module, 'file') || $module === 'media_management';
    }

    private function resolveFileUid(int $storageUid, string $identifier): int
    {
        if ($storageUid <= 0 || trim($identifier) === '') {
            return 0;
        }

        try {
            $info = $this->fileService->getFileInfo($storageUid, $identifier);

            return (int) ($info['uid'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function toolIsExecutable(string $toolName): bool
    {
        foreach ($this->permittedActionProvider->buildCatalog()['executable'] as $tool) {
            if (strtolower((string) ($tool['name'] ?? '')) === strtolower($toolName)) {
                return ToolSeverity::tryFromString((string) ($tool['severity'] ?? '')) !== null;
            }
        }

        return false;
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        $value = $languageService->sL('LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key) ?: $key;

        return $arguments !== [] ? sprintf($value, ...array_map(static fn(int|string $arg): string => (string) $arg, $arguments)) : $value;
    }
}
