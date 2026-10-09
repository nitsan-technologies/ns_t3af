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

use NITSAN\NsT3AF\Agent\Context\AgentContextPresenter;
use NITSAN\NsT3AF\Service\BrandContextAssembler;
use NITSAN\NsT3AF\Service\BrandContextResolver;

/**
 * Builds the system prompt and the chat history the AI Agent sends to the model.
 *
 * Used by {@see AgentRunner}.
 *
 * @internal
 */
readonly class AgentPromptBuilder
{
    /** Characters of history when no budget is passed (≈ 6000 tokens). */
    public const DEFAULT_HISTORY_CHARS = 24000;

    /** One replayed message is cut after this many characters. */
    public const HISTORY_MESSAGE_CHARS = 2000;

    public function __construct(
        private BrandContextResolver $brandContextResolver,
        private BrandContextAssembler $brandContextAssembler,
        private AgentLanguageResolver $languageResolver,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function buildSystemPrompt(array $context): string
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $profile = $this->brandContextResolver->resolveDefaultForPageId($pageId > 0 ? $pageId : null);
        $persona = $profile !== null ? $this->brandContextAssembler->assemble($profile) : '';

        $lines = [
            'You are the TYPO3 backend AI Agent. Use the provided tools to answer questions and prepare changes.',
            'You are built into AI Foundation for TYPO3 (EXT:ns_t3af), by T3Planet / NITSAN Technologies. Never attribute yourself to the TYPO3 community, the TYPO3 Association, or any underlying AI provider by name.',
            $this->languageResolver->replyLanguageInstruction(),
            'Read tools run immediately. Write tools produce drafts that require explicit editor approval.',
            'Prefer concise answers grounded in tool results. Never claim a change was saved unless the editor applied a draft.',
            'When the user asks to create, update, translate, or generate content, prefer calling the most specific write tool instead of replying with text only.',
            'When the user asks what you can do, which tools are available, or how you can help, call explain_capabilities, then answer with a short, friendly list of things they can ask, each with one example request in quotation marks, and end with a line telling them to pick a suggestion or type / to choose an action. In German use the formal Sie. No tool names or technical terms.',
            'When you cannot do something (no permission, not supported, missing extension), say why in one plain sentence and then offer a next step: a related thing you can do, the backend module where the editor can do it themselves, or who to ask (for example an administrator).',
            'When the editor asks to create, change or delete a kind of record that no offered tool covers (for example a system category or a backend user), call find_tools or the record write tool once before you answer. Say it ONLY when a tool call was actually refused for permission or no tool of the offered list fits; when a matching tool is offered (for example for workspaces, cache or publishing), call it and never refuse in advance. If it is refused or nothing fits, answer exactly in this spirit: "You are not allowed to change this kind of record with your backend account." Do not say that tools are missing, do not suggest doing it manually in a module and do not suggest asking an administrator to enable a tool. Say it once, in one short sentence.',
            'Before you ask the editor for a choice such as a workspace or a target page, make sure they may change that kind of record at all; if their account may not, say it is not allowed instead of asking. Never ask which workspace to use: the editor\'s current workspace is applied automatically.',
            'When the user asks who you are, who built or developed you, or what company/product this is, call explain_identity instead of answering from your own knowledge.',
            'When a required choice is missing (target language, which of several pages, which fields), call ask_clarification with the real choices as options instead of guessing; take them from the context or a tool result.',
            'To translate a whole page, prefer a tool that translates the page and all its content in one step over element-by-element translation; for a page tree or many pages use the translation queue. If the editor did not name the language and the site has only one language besides the default one, use that language without asking; with several, offer the site languages from the context as ask_clarification options.',
            'Write for editors: plain language, no tool names, ids only where they help to identify a record.',
            'Only some tools are offered at first. If none fits the request, call find_tools with a short description of the task before you say that something is not possible.',
            'For a request that needs three or more steps (for example create a page, add content, then translate it), call update_plan first with the steps (the first one in_progress), and again after every finished step (mark it completed, start the next). Never for a simple request; never as a substitute for doing the work.',
            'When the editor asks for several content elements of different types (e.g. Text & Media, Text, Bullets), call update_plan with one step per type (and a step to attach an image when they asked for one). After each apply, do the next distinct CType — never create another element of the same CType you just applied. Do not say the request is finished while the plan still has pending or in_progress steps, or while an image still needs attaching.',
            'For a page or record you just created, pass its uid from the applied result (pageId, uid) to the next tool; never guess a pageUrl or slug for it.',
            'Never respond with an empty message: call a tool or write a short answer.',
            'Never reply with lines that look like history notes such as "[Prepared change: …]" or "Review the proposed changes for …" — those are internal records of past drafts, not answers. Call a write tool or write a normal sentence.',
            'If a tool result says a tool is not available or was not executed, tell the editor in one sentence instead of retrying it.',
            'Use pageId/pid/uid from context when a tool accepts a page or storage folder id.',
            'Read results earlier in this conversation are still valid: do not read the same record or run the same search again; use what you have.',
            'Do not ask the editor in text whether you should make a change ("Shall we proceed?"): call the write tool; it only prepares the change, and the editor confirms or declines it in the window. If no offered tool can make the change, call find_tools first.',
            'Read only what you need, then act. To create or change something, call the write tool as soon as you know the target; the editor reviews it before anything is saved.',
            'To add an image to a new content element: first prepare the element (e.g. a text & media element), after it is applied attach the image to its uid with the file reference tool (field "assets" for text & media, "image" for text & images). The page uid is never a content element uid.',
            'Do not call image generation until a Text & Media (textmedia) element for that image was applied, unless the editor asked only for a standalone image file. Mark a Text & Media plan step completed only after its element exists (and attach when they asked for an image).',
            'Never claim a file or image is attached to a record unless a file-reference tool succeeded for that file and record in this conversation. Generating or uploading a file alone does not attach it.',
            'To create a new page, prepare a new record in the pages table (or use a create-page tool); copying a page is only for "copy" or "duplicate" requests. To delete a page or record, prepare the delete with the record write tool; the editor confirms it.',
            'Page position uses pid. A positive pid creates the page as the first child of that page. A negative pid (-uid) creates it directly after that page. "After Page 1" is write_table create with afterUid set to Page 1\'s uid. Do not send Page 1\'s uid as a positive pid for an after request. "Before Page 2" is write_table create with beforeUid set to Page 2\'s uid. Do not send the parent uid as pid for a before request. "Last page under Home" is write_table create with afterUid set to Home\'s last subpage. "Subpage of Page 1" is Page 1\'s uid as a positive pid.',
            'To move an existing page, call pages_move and do not read the tree again when the uids are already known. beforeUid places it directly before that page. afterUid places it directly after that page. targetPid places it as the first child of that page. A hidden page can be moved. Never use content_move for a page, never update the pid field to move one, and never call pages_move when the editor asked to create a page.',
            'To find a page by name, call pages_search with that name only and no pid. A hidden match is a real page: use its uid. "After page Sample" is pages_move with afterUid of that uid. Do not say the page does not exist.',
            'To create a page with content: first prepare the new page (only the page). After the editor applies it, the result gives the new page uid; then prepare the content elements with that uid as their pid.',
            'When the editor asks to create or add content elements, use a create/write tool. Do not call content_delete unless they asked to remove or replace something.',
            'To change or delete a content element, use its uid from the latest content_list or content_get result; uids from older messages may no longer exist. The uid of a record you just created (from the applied result) is valid.',
        ];

        if ($pageId > 0) {
            $languageId = isset($context['languageId']) ? (int) $context['languageId'] : null;
            $lines[] = $this->languageResolver->contentLanguageInstruction($pageId, $languageId);
        }

        if ($persona !== '') {
            $lines[] = 'Brand context (persona):';
            $lines[] = $persona;
        }

        $block = AgentContextPresenter::promptBlock($context);
        if ($block !== '') {
            $lines[] = $block;
        } else {
            // Context without details (e.g. CLI / eval): the plain ids.
            $module = trim((string) ($context['module'] ?? ''));
            if ($module !== '') {
                $lines[] = 'Current backend module: ' . $module;
            }
            if ($pageId > 0) {
                $lines[] = 'Current page id: ' . $pageId;
            }
            $record = is_array($context['record'] ?? null) ? $context['record'] : null;
            if ($record !== null) {
                $lines[] = 'Focused record: ' . ($record['table'] ?? '') . ':' . ($record['uid'] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Records the editor applied in this conversation (newest first), so a later step can address
     * them by uid instead of guessing a URL or slug.
     *
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function appliedRecordsBlock(array $historyMessages): string
    {
        $records = [];
        for ($i = count($historyMessages) - 1; $i >= 0 && count($records) < 8; --$i) {
            $meta = is_array($historyMessages[$i]['meta'] ?? null) ? $historyMessages[$i]['meta'] : [];
            if (($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            $note = trim(self::appliedRecordsNote($meta), ' .');
            if (str_starts_with($note, 'Records: ')) {
                foreach (explode('; ', substr($note, strlen('Records: '))) as $record) {
                    $records[$record] = true;
                }
            }
        }

        return $records === []
            ? ''
            : 'Records the editor applied in this conversation (use these uids as pageId / pid / uid, never a guessed URL): '
                . implode('; ', array_slice(array_keys($records), 0, 8)) . '.';
    }

    /**
     * The plan of this conversation as the model last saved it (meta.plan of the newest message that
     * carries one), as a prompt block; empty when there is none or every step is completed.
     *
     * @param list<array<string, mixed>> $historyMessages
     */
    public static function planBlock(array $historyMessages): string
    {
        return AgentPlan::promptBlock(AgentPlan::latest($historyMessages));
    }

    /**
     * Earlier messages for the model, newest first until the character budget is used up.
     *
     * - The latest "Summarize conversation" message replaces everything before it.
     * - Cards and results are replayed as short bracketed notes ("[Prepared change: … — applied]"),
     *   so the model knows what was done without the raw data.
     * - A user message written on another page than the current one is prefixed with that page,
     *   so "this page" in an older message is not confused with the page shown now.
     * - When older messages had to be left out, the history starts with a note saying so.
     *
     * @param list<array<string, mixed>> $historyMessages
     * @param int $charBudget characters (≈ 4 per token) for all replayed messages
     * @return list<array{role: string, content: string}>
     */
    public function buildHistory(array $historyMessages, int $currentPageId = 0, int $charBudget = self::DEFAULT_HISTORY_CHARS): array
    {
        return $this->buildHistoryAfterSummary(array_values(array_filter($historyMessages, 'is_array')), '', $currentPageId, $charBudget);
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array{role: string, content: string}>
     */
    private function buildHistoryAfterSummary(array $historyMessages, string $summary, int $currentPageId, int $charBudget): array
    {
        // A later summary replaces an earlier one.
        foreach ($historyMessages as $index => $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') === 'summary' && trim((string) ($entry['content'] ?? '')) !== '') {
                return $this->buildHistoryAfterSummary(
                    array_slice($historyMessages, $index + 1),
                    trim((string) $entry['content']),
                    $currentPageId,
                    $charBudget,
                );
            }
        }

        $entries = [];
        foreach ($historyMessages as $entry) {
            $replayed = $this->historyEntry($entry, $currentPageId);
            if ($replayed !== null) {
                $entries[] = $replayed;
            }
        }

        $budget = max(1000, $charBudget);
        if ($summary !== '') {
            $summary = self::shorten($summary, (int) ($budget / 3));
            $budget -= mb_strlen($summary);
        }

        $kept = [];
        $used = 0;
        for ($i = count($entries) - 1; $i >= 0; --$i) {
            $content = self::shorten($entries[$i]['content'], self::HISTORY_MESSAGE_CHARS);
            $length = mb_strlen($content);
            if ($kept !== [] && $used + $length > $budget) {
                break;
            }
            $used += $length;
            array_unshift($kept, ['role' => $entries[$i]['role'], 'content' => $content]);
        }
        $leftOut = count($entries) - count($kept);

        $prefix = [];
        if ($summary !== '') {
            $prefix[] = ['role' => 'user', 'content' => "[Summary of the earlier conversation]\n" . $summary];
        }
        if ($leftOut > 0) {
            $prefix[] = ['role' => 'user', 'content' => sprintf('[%d older message(s) of this conversation are left out.]', $leftOut)];
        }

        return [...$prefix, ...$kept];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{role: string, content: string}|null
     */
    private function historyEntry(array $entry, int $currentPageId): ?array
    {
        $role = (string) ($entry['role'] ?? 'user');
        if (!in_array($role, ['user', 'assistant'], true)) {
            return null;
        }
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $type = (string) ($meta['type'] ?? '');
        if (in_array($type, ['provider_thinking', 'error', 'locked', 'summary'], true)) {
            return null;
        }
        $content = $this->historyContent($entry, $meta);
        $label = $this->cardLabel($meta);

        $content = match ($type) {
            'tool_result' => sprintf(
                '[%s%s] %s%s',
                $label !== '' ? $label : 'Tool result',
                ($meta['success'] ?? true) === false ? ' failed' : '',
                $content,
                ($meta['autoRan'] ?? true) === false ? self::resultRecordsNote($meta['details'] ?? null) : '',
            ),
            'inline_draft', 'suggestions' => self::preparedChangeHistoryNote(
                $label !== '' ? $label : 'change',
                $this->cardStatus($meta),
                $content,
            ),
            'readback_result' => '[Applied] ' . $content . self::appliedRecordsNote($meta),
            'clarification' => $content . (is_array($meta['options'] ?? null) && $meta['options'] !== []
                ? ' (options: ' . implode(', ', array_map('strval', array_filter($meta['options'], 'is_scalar'))) . ')'
                : ''),
            default => $content,
        };
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $written = is_array($meta['context'] ?? null) ? $meta['context'] : [];
        $writtenPageId = (int) ($written['pageId'] ?? 0);
        if ($role === 'user' && $writtenPageId > 0 && $writtenPageId !== $currentPageId) {
            $content = sprintf('[written on page "%s" [%d]] ', (string) ($written['pageTitle'] ?? ''), $writtenPageId) . $content;
        }

        return ['role' => $role, 'content' => $content];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function cardLabel(array $meta): string
    {
        $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : $meta;
        foreach (['editorLabel', 'toolCallLabel', 'label', 'tool'] as $key) {
            $label = trim((string) ($draft[$key] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function cardStatus(array $meta): string
    {
        $state = is_array($meta['draft'] ?? null) ? $meta['draft'] : $meta;
        if (($state['applied'] ?? false) === true) {
            return 'applied';
        }
        if (($state['discarded'] ?? false) === true) {
            return 'declined';
        }

        return 'waiting for the editor';
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
    public static function continuationMessage(array $continuation, array $history = []): string
    {
        $label = mb_substr(trim((string) ($continuation['label'] ?? '')), 0, 120);
        $result = mb_substr(trim((string) ($continuation['result'] ?? '')), 0, 600);
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') === 'user') {
                break;
            }
            if (($meta['type'] ?? '') === 'readback_result') {
                $result = trim($result . self::appliedRecordsNote($meta));
                break;
            }
            // A confirmed tool (create content element, generate image, …): the ids it returned.
            if (($meta['type'] ?? '') === 'tool_result' && ($meta['autoRan'] ?? true) === false) {
                $result = trim($result . self::resultRecordsNote($meta['details'] ?? null));
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

        $requestText = self::latestUserRequestText($history);
        // An edit of existing records ("change the header of element 5") has no create step: nothing new may follow.
        $changesExistingOnly = $requestText !== '' && AgentRequestChecklist::parse($requestText) === [];
        $message = sprintf(
            '[The editor confirmed "%s" and it was applied.%s] Continue with the remaining steps of my request, if there are any;'
            . ' if this change already completed it, just confirm that in one short sentence.'
            . '%s'
            . ' Only when every part of my original request is already done through tools, confirm in one short sentence without calling tools.'
            . ' Never claim a file or image is attached unless a file-reference step succeeded.'
            . ' Do not re-check finished results or list what else you can do.',
            $label,
            $result !== '' ? ' Result: ' . $result : '',
            $changesExistingOnly
                ? ' My request changes existing records and asks for nothing new: do not create any record or content element.'
                    . ' Prepare another change only if my request names one that is not applied yet.'
                : ' Do not create another content element of the same CType you just applied; prepare the next distinct type from my request, or attach a pending image.',
        );
        $lastCType = self::lastAppliedTtContentCType($history);
        if ($lastCType !== '') {
            $message .= sprintf(
                ' You just applied tt_content with CType "%1$s"; do not create another "%1$s" unless that was the only type requested.',
                $lastCType,
            );
        }
        $request = self::latestUserRequestText($history);
        if ($request !== '') {
            $message .= sprintf(' My request was: "%s".', mb_substr(preg_replace('/\s+/u', ' ', $request) ?? $request, 0, 300));
        }
        $reminder = self::remainingWorkReminder($history, AgentPlan::latest($history), $request);

        return $reminder !== '' ? $message . ' ' . $reminder : $message;
    }

    /**
     * Some models send the same answer twice in one message ("…ask your administrator.…ask your administrator.").
     * Returns the text once when it is two identical halves, otherwise unchanged.
     */
    public static function collapseRepeatedReply(string $text): string
    {
        $text = trim($text);
        $length = strlen($text);
        if ($length < 20) {
            return $text;
        }
        foreach (['', ' ', "\n", "\n\n"] as $separator) {
            $rest = $length - strlen($separator);
            if ($rest <= 0 || $rest % 2 !== 0) {
                continue;
            }
            $half = intdiv($rest, 2);
            $first = substr($text, 0, $half);
            if ($first !== '' && substr($text, $half, strlen($separator)) === $separator && substr($text, $half + strlen($separator)) === $first) {
                return trim($first);
            }
        }

        return $text;
    }

    /**
     * Editor's newest non-continuation user message (the original multi-step request when present).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function latestUserRequestText(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($meta['type'] ?? '') === 'continuation') {
                continue;
            }
            $text = trim((string) ($history[$i]['content'] ?? ''));
            // The follow-up after a confirmed card is not a request: its quoted label must never be read as one.
            if (str_starts_with($text, '[The editor ')) {
                continue;
            }

            return $text;
        }

        return '';
    }

    public static function requestMentionsImage(string $query): bool
    {
        return preg_match(
            '/\b(image|images|bild|bilder|foto|photo|illustration|textmedia|text\s*&\s*media|text\s+and\s+media)\b/ui',
            $query,
        ) === 1;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array{title: string, status: string}> $plan
     */
    public static function remainingWorkReminder(array $history, array $plan, string $requestQuery = ''): string
    {
        $query = trim($requestQuery) !== '' ? $requestQuery : self::latestUserRequestText($history);
        $parts = [];

        $checklist = AgentRequestChecklist::reconcile($history, $query);
        if ($checklist !== []) {
            $block = AgentRequestChecklist::promptBlock($checklist);
            if ($block !== '') {
                $parts[] = $block;
            }
        } elseif ($plan !== [] && AgentPlan::hasOpenSteps($plan)) {
            $block = AgentPlan::promptBlock($plan);
            if ($block !== '') {
                $parts[] = $block;
            }
        }

        // A file saved in an earlier request and never attached must not make an unrelated request
        // ("rename this header") look unfinished: it counts when the request is about images, or when the
        // file was saved while working on this request.
        $pending = '';
        if (self::requestMentionsImage($query)) {
            $pending = self::pendingImageAttachNote($history);
        } elseif (self::unattachedFileUids(AgentRequestChecklist::historySinceLatestUserRequest($history, $query)) !== []) {
            $pending = self::pendingImageAttachNote($history);
        }
        if ($pending !== '') {
            $parts[] = $pending;
        }
        $missing = self::missingMediaImageNote($history, $query, $plan);
        if ($missing !== '' && $checklist === []) {
            // Checklist already encodes attach_image when present; avoid duplicating.
            $parts[] = $missing;
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array{title: string, status: string}> $plan
     */
    public static function hasBlockingRemainingWork(array $history, array $plan, string $requestQuery = ''): bool
    {
        $query = trim($requestQuery) !== '' ? $requestQuery : self::latestUserRequestText($history);
        $checklist = AgentRequestChecklist::reconcile($history, $query);
        if ($checklist !== [] && AgentRequestChecklist::hasOpen($checklist)) {
            return true;
        }

        return self::remainingWorkReminder($history, $plan, $query) !== '';
    }

    /**
     * Media-capable tt_content uid created in this conversation but never got file_reference_add.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function unattachedMediaContentElementUid(array $history): ?int
    {
        $attached = self::contentElementUidsWithSuccessfulFileReference($history);
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') !== 'assistant' || ($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $entry) {
                if (!is_array($entry) || ($entry['table'] ?? '') !== 'tt_content' || (int) ($entry['uid'] ?? 0) <= 0) {
                    continue;
                }
                $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
                $cType = strtolower(trim((string) ($values['CType'] ?? '')));
                if (!in_array($cType, ['textmedia', 'textpic', 'image'], true)) {
                    continue;
                }
                $uid = (int) $entry['uid'];
                if (!isset($attached[$uid])) {
                    return $uid;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param list<array{title: string, status: string}> $plan
     */
    public static function missingMediaImageNote(array $history, string $requestQuery, array $plan = []): string
    {
        if (self::unattachedFileUids($history) !== []) {
            return '';
        }
        $uid = self::unattachedMediaContentElementUid($history);
        if ($uid === null) {
            return '';
        }
        if (!self::requestMentionsImage($requestQuery) && !AgentPlan::hasOpenImageAttachStep($plan)) {
            return '';
        }

        return sprintf(
            'Remaining: tt_content uid %d still has no image in this conversation.'
            . ' Call t3ai_generate_image (or use an uploaded file), then file_reference_add on that uid'
            . ' (fieldName "assets" for textmedia, "image" for textpic). Do not confirm completion until attach succeeds.',
            $uid,
        );
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<int, true>
     */
    private static function contentElementUidsWithSuccessfulFileReference(array $history): array
    {
        $uids = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') !== 'tool_result' || ($meta['success'] ?? true) === false) {
                continue;
            }
            if ((string) ($meta['tool'] ?? '') !== 'file_reference_add') {
                continue;
            }
            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            if ((string) ($details['table'] ?? '') !== 'tt_content') {
                continue;
            }
            $uid = (int) ($details['uid'] ?? 0);
            if ($uid > 0) {
                $uids[$uid] = true;
            }
        }

        return $uids;
    }

    /**
     * CType of the newest applied tt_content row in history (empty when none).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function lastAppliedTtContentCType(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            $readback = is_array($meta['readback'] ?? null) ? $meta['readback'] : [];
            for ($j = count($readback) - 1; $j >= 0; --$j) {
                $row = is_array($readback[$j] ?? null) ? $readback[$j] : [];
                if (($row['table'] ?? '') !== 'tt_content') {
                    continue;
                }
                $values = is_array($row['values'] ?? null) ? $row['values'] : [];
                $cType = strtolower(trim((string) ($values['CType'] ?? $values['ctype'] ?? '')));
                if ($cType !== '') {
                    return $cType;
                }
            }
        }

        return '';
    }

    /**
     * When a generated/uploaded file still has no file reference and a content element exists,
     * tell the model that attach is still open (do not let it declare the request done early).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function pendingImageAttachNote(array $history): string
    {
        $fileUids = self::unattachedFileUids($history);
        if ($fileUids === []) {
            return '';
        }
        $fileUidList = implode(', ', array_map(static fn(int $uid): string => (string) $uid, $fileUids));
        $contentUid = self::latestAppliedContentElementUid($history);
        if ($contentUid === null) {
            return sprintf(
                'Remaining: fileUid %s is saved but not attached. Prepare a Text & Media element (CType textmedia) and wait until the editor applies it,'
                . ' then attach fileUid %s to that tt_content uid with file_reference_add (fieldName "assets").'
                . ' Do not attach to plain Text or Bullets elements. Do not confirm completion until the attach succeeds.',
                $fileUidList,
                $fileUidList,
            );
        }

        return sprintf(
            'Remaining: attach fileUid %s to tt_content uid %d by calling file_reference_add'
            . ' (fieldName "assets" for text & media, "image" for textpic). Do not use write_table for file fields.'
            . ' Do not attach to non-media content types. Do not confirm completion until that succeeds.',
            $fileUidList,
            $contentUid,
        );
    }

    /**
     * sys_file uids from successful writes that are not yet covered by a successful file_reference_add.
     *
     * @param list<array<string, mixed>> $history
     * @return list<int>
     */
    public static function unattachedFileUids(array $history): array
    {
        $created = [];
        $attached = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || ($entry['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') !== 'tool_result' || ($meta['success'] ?? true) === false) {
                continue;
            }
            $tool = (string) ($meta['tool'] ?? '');
            $details = $meta['details'] ?? null;
            if ($tool === 'file_reference_add') {
                foreach (self::fileUidsFromDetails($details) as $uid) {
                    $attached[$uid] = true;
                }
                continue;
            }
            foreach (self::fileUidsFromDetails($details) as $uid) {
                $created[$uid] = true;
            }
            foreach (is_array($meta['previews'] ?? null) ? $meta['previews'] : [] as $preview) {
                if (is_array($preview) && (int) ($preview['fileUid'] ?? 0) > 0) {
                    $created[(int) $preview['fileUid']] = true;
                }
            }
        }
        $pending = [];
        foreach (array_keys($created) as $uid) {
            if (!isset($attached[$uid])) {
                $pending[] = $uid;
            }
        }

        return $pending;
    }

    /**
     * Newest applied tt_content uid that can hold the image (textmedia / textpic / image), or null.
     *
     * @param list<array<string, mixed>> $history
     */
    public static function latestAppliedContentElementUid(array $history): ?int
    {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($history[$i]['role'] ?? '') !== 'assistant' || ($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $entry) {
                if (!is_array($entry) || ($entry['table'] ?? '') !== 'tt_content' || (int) ($entry['uid'] ?? 0) <= 0) {
                    continue;
                }
                $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
                $cType = strtolower(trim((string) ($values['CType'] ?? '')));
                if (in_array($cType, ['textmedia', 'textpic', 'image'], true)) {
                    return (int) $entry['uid'];
                }
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private static function fileUidsFromDetails(mixed $details): array
    {
        if (!is_array($details)) {
            return [];
        }
        $uids = [];
        foreach (['fileUid', 'file_uid', 'sysFileUid', 'fileId'] as $key) {
            if ((int) ($details[$key] ?? 0) > 0) {
                $uids[] = (int) $details[$key];
            }
        }
        $list = $details['fileUids'] ?? null;
        if (is_string($list) && $list !== '') {
            foreach (explode(',', $list) as $part) {
                $uid = (int) trim($part);
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        } elseif (is_array($list)) {
            foreach ($list as $value) {
                $uid = (int) $value;
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        }
        foreach (['file', 'result', 'data'] as $nest) {
            if (is_array($details[$nest] ?? null)) {
                foreach (self::fileUidsFromDetails($details[$nest]) as $uid) {
                    $uids[] = $uid;
                }
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * " Records: pages 145 "AI Universe vs Symfony"; tt_content 812" for an applied change, so the
     * model can use a new record's uid (e.g. as pid of its content) without searching for it.
     *
     * @param array<string, mixed> $meta meta of a readback_result message
     */
    public static function appliedRecordsNote(array $meta): string
    {
        $records = [];
        foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $entry) {
            if (!is_array($entry) || (int) ($entry['uid'] ?? 0) <= 0) {
                continue;
            }
            $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
            $title = '';
            foreach (['title', 'header', 'name'] as $field) {
                if (is_scalar($values[$field] ?? null) && trim((string) $values[$field]) !== '') {
                    $title = trim((string) $values[$field]);
                    break;
                }
            }
            $pid = is_scalar($values['pid'] ?? null) ? (int) $values['pid'] : 0;
            $records[] = trim(sprintf(
                '%s uid %d%s%s',
                (string) ($entry['table'] ?? ''),
                (int) $entry['uid'],
                $title !== '' ? ' "' . self::shorten($title, 80) . '"' : '',
                $pid > 0 ? ' (pid ' . $pid . ')' : '',
            ));
            if (count($records) >= 10) {
                break;
            }
        }

        return $records !== [] ? ' Records: ' . implode('; ', $records) . '.' : '';
    }

    /** Keys of a tool result that identify what was created or changed. */
    private const RESULT_ID_KEYS = [
        'table', 'uid', 'pid', 'pageId', 'pageUid', 'contentUid', 'contentElementUid', 'elementId',
        'recordUid', 'newUid', 'fileUid', 'referenceUids', 'languageId', 'sysLanguageUid',
    ];

    /**
     * " Ids: table tt_content, uid 812, pid 128" from a confirmed tool's result, so the next step
     * (e.g. attaching an image to the new element) uses the right record.
     */
    public static function resultRecordsNote(mixed $details): string
    {
        if (!is_array($details)) {
            return '';
        }
        $sources = [$details];
        foreach (['record', 'result', 'data', 'contentElement', 'page', 'file'] as $key) {
            if (is_array($details[$key] ?? null) && !array_is_list($details[$key])) {
                $sources[] = $details[$key];
            }
        }
        $parts = [];
        foreach ($sources as $source) {
            foreach (self::RESULT_ID_KEYS as $key) {
                $value = $source[$key] ?? null;
                if (is_array($value) && array_is_list($value)) {
                    $value = implode(',', array_filter($value, 'is_scalar'));
                }
                if ((is_int($value) || (is_string($value) && $value !== '' && mb_strlen($value) <= 60)) && !isset($parts[$key])) {
                    $parts[$key] = $key . ' ' . $value;
                }
            }
        }

        return $parts !== [] ? ' Ids: ' . implode(', ', array_slice($parts, 0, 8)) . '.' : '';
    }

    private static function shorten(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '…' : $text;
    }

    /**
     * History note for a draft card. Omits the generic "Review the proposed changes…" body so the
     * model does not learn to parrot that UI copy as a final answer.
     */
    public static function preparedChangeHistoryNote(string $label, string $status, string $body): string
    {
        $prefix = sprintf('[Prepared change: %s — %s]', $label, $status);
        $body = trim($body);
        if ($body === '' || self::isDraftReviewBoilerplate($body)) {
            return $prefix;
        }

        return $prefix . ' ' . $body;
    }

    /**
     * True when text is (or contains) an internal draft-history note, not a real editor-facing reply.
     */
    public static function isCardHistoryEcho(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (str_contains($text, '[Prepared change:')) {
            return true;
        }

        return self::isDraftReviewBoilerplate($text);
    }

    public static function isDraftReviewBoilerplate(string $text): bool
    {
        return preg_match(
            '/^Review (?:the proposed changes for .+|this change) before anything is written\.?$/iu',
            trim($text),
        ) === 1;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $meta
     */
    private function historyContent(array $entry, array $meta): string
    {
        foreach (['llmSummary', 'summary'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '') {
                return trim($meta[$key]);
            }
        }

        return trim((string) ($entry['content'] ?? ''));
    }
}
