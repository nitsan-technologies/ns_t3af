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
            $this->languageResolver->replyLanguageInstruction(),
            'Read tools run immediately. Write tools produce drafts that require explicit editor approval.',
            'Prefer concise answers grounded in tool results. Never claim a change was saved unless the editor applied a draft.',
            'When the user asks to create, update, translate, or generate content, prefer calling the most specific write tool instead of replying with text only.',
            'When the user asks what you can do, which tools are available, or how you can help, call explain_capabilities.',
            'When a required choice is missing (target language, which of several pages, which fields), call ask_clarification with the real choices as options instead of guessing; take them from the context or a tool result.',
            'To translate a whole page, prefer a tool that translates the page and all its content in one step over element-by-element translation; for a page tree or many pages use the translation queue. If the editor did not name the languages, offer the site languages from the context as ask_clarification options.',
            'Write for editors: plain language, no tool names, ids only where they help to identify a record.',
            'Only some tools are offered at first. If none fits the request, call find_tools with a short description of the task before you say that something is not possible.',
            'Never respond with an empty message: call a tool or write a short answer.',
            'If a tool result says a tool is not available or was not executed, tell the editor in one sentence instead of retrying it.',
            'Use pageId/pid/uid from context when a tool accepts a page or storage folder id.',
            'Read results earlier in this conversation are still valid: do not read the same record or run the same search again; use what you have.',
            'Do not ask the editor in text whether you should make a change ("Shall we proceed?"): call the write tool; it only prepares the change, and the editor confirms or declines it in the window. If no offered tool can make the change, call find_tools first.',
            'Read only what you need, then act. To create or change something, call the write tool as soon as you know the target; the editor reviews it before anything is saved.',
            'To create a page with content: first prepare the new page (only the page). After the editor applies it, the result gives the new page uid; then prepare the content elements with that uid as their pid.',
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
                '[%s%s] %s',
                $label !== '' ? $label : 'Tool result',
                ($meta['success'] ?? true) === false ? ' failed' : '',
                $content,
            ),
            'inline_draft', 'suggestions' => sprintf(
                '[Prepared change: %s — %s] %s',
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

    private static function shorten(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '…' : $text;
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
