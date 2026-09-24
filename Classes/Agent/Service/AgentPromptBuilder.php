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
    public const HISTORY_MESSAGE_LIMIT = 12;

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
     * Earlier turns as plain role/content pairs (tool rounds are summarised by their llmSummary).
     *
     * A user message written on another page than the current one is prefixed with that page,
     * so "this page" in an older message is not confused with the page shown now.
     *
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array{role: string, content: string}>
     */
    public function buildHistory(array $historyMessages, int $currentPageId = 0): array
    {
        $messages = [];
        foreach (array_slice($historyMessages, -self::HISTORY_MESSAGE_LIMIT) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = (string) ($entry['role'] ?? 'user');
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($meta['type'] ?? '') === 'provider_thinking') {
                continue;
            }
            $content = $this->historyContent($entry, $meta);
            if ($content === '') {
                continue;
            }
            $written = is_array($meta['context'] ?? null) ? $meta['context'] : [];
            $writtenPageId = (int) ($written['pageId'] ?? 0);
            if ($role === 'user' && $writtenPageId > 0 && $writtenPageId !== $currentPageId) {
                $content = sprintf('[written on page "%s" [%d]] ', (string) ($written['pageTitle'] ?? ''), $writtenPageId) . $content;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        return $messages;
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
