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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Updates\AgentConversationSessionsUpdate;

/**
 * One short conversation title after the first real assistant reply (Claude-style list labels).
 *
 * @internal
 */
final readonly class AgentConversationTitleService
{
    public const MAX_WORDS = 6;

    public const MAX_CHARS = 48;

    public function __construct(
        private AiServiceInterface $aiService,
        private AgentLanguageResolver $languageResolver,
        private ?AgentToolEditorLabelService $toolLabels = null,
    ) {}

    /**
     * A message that starts with a picked "/tool_name" is titled by the action's plain name
     * ("Check page permissions"), never by the tool id. Any other text is returned unchanged.
     */
    public function readable(string $userLine): string
    {
        if ($this->toolLabels === null || preg_match('/^\/([a-z][a-z0-9_]*)(?:\s+(.*))?$/su', trim($userLine), $match) !== 1) {
            return $userLine;
        }

        $label = trim($this->toolLabels->resolveByName($match[1]));
        if ($label === '') {
            return $userLine;
        }
        $rest = trim($match[2] ?? '');

        return $rest !== '' ? $label . ' ' . $rest : $label;
    }

    /**
     * Title as shown in the conversation list; also repairs older conversations that were titled with a tool id.
     */
    public function displayTitle(string $stored): string
    {
        $readable = $this->readable($stored);

        return $readable === $stored ? $stored : (string) $this->normalizeTitle($readable);
    }

    /**
     * Fast list title from the first user message — no LLM (hot path).
     *
     * @param list<array<string, mixed>> $messages
     */
    public function suggestWithoutLlm(array $messages, int $pageId = 0): ?string
    {
        $userLine = $this->firstUserLine($messages);
        if ($userLine === '') {
            return null;
        }

        return $this->heuristicTitle($userLine, $pageId);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function suggest(array $messages, string $providerIdentifier = '', int $pageId = 0): ?string
    {
        $userLine = '';
        $assistantLine = '';
        foreach ($messages as $message) {
            if (($message['meta']['hidden'] ?? false) === true) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) preg_replace('/\s+/u', ' ', (string) ($message['content'] ?? '')));
            if ($content === '') {
                continue;
            }
            if ($role === 'user' && $userLine === '') {
                $userLine = mb_substr($content, 0, 400);
            }
            if ($role === 'assistant' && $assistantLine === '' && $this->isTitleWorthyAssistant($message)) {
                $assistantLine = mb_substr($content, 0, 400);
            }
        }
        if ($userLine === '') {
            return null;
        }
        $userLine = $this->readable($userLine);

        try {
            $prompt = implode("\n", [
                'Write a short title for this TYPO3 backend AI Agent conversation.',
                'At most ' . self::MAX_WORDS . ' words, no quotes, no punctuation at the end, no tool names.',
                'Editor-facing, like Claude chat titles.',
                $this->languageResolver->backendLanguageInstruction(),
                '',
                'Editor: ' . $userLine,
                $assistantLine !== '' ? 'Agent: ' . $assistantLine : '',
            ]);

            $response = $this->aiService->complete($prompt, new AiOptions(
                providerIdentifier: in_array($providerIdentifier, ['', AgentProviderOptions::DEFAULT], true) ? null : $providerIdentifier,
                temperature: 0.2,
                maxTokens: 40,
                extensionKey: 'ns_t3af',
                featureKey: 'agent.conversation_title',
                featureLabel: 'AI Agent conversation title',
                requestSource: 'agent',
                pageId: $pageId > 0 ? $pageId : null,
                extra: ['skipBrandContext' => true],
            ));
            $title = $this->normalizeTitle($response->content);
            if ($title !== null) {
                return $title;
            }
        } catch (\Throwable) {
            // Fall through to heuristic.
        }

        return $this->heuristicTitle($userLine, $pageId);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function firstUserLine(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['meta']['hidden'] ?? false) === true) {
                continue;
            }
            if ((string) ($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = trim((string) preg_replace('/\s+/u', ' ', (string) ($message['content'] ?? '')));
            if ($content !== '') {
                return mb_substr($content, 0, 400);
            }
        }

        return '';
    }

    public function heuristicTitle(string $userLine, int $pageId = 0): ?string
    {
        $title = $this->normalizeTitle($this->readable($userLine));
        if ($title !== null) {
            return $title;
        }

        $fallback = AgentConversationSessionsUpdate::titleFromMessages([
            ['role' => 'user', 'content' => $userLine],
        ], $pageId);

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function isTitleWorthyAssistant(array $message): bool
    {
        $type = (string) ($message['meta']['type'] ?? '');

        return in_array($type, ['nl_reply', 'tool_result', 'clarification', 'not_purchased', ''], true)
            || (($message['role'] ?? '') === 'assistant' && $type !== 'summary' && $type !== 'error');
    }

    public function normalizeTitle(string $raw): ?string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $title = trim($title, " \t\n\r\0\x0B\"'`");
        if ($title === '') {
            return null;
        }
        $words = preg_split('/\s+/u', $title) ?: [];
        if (count($words) > self::MAX_WORDS) {
            $words = array_slice($words, 0, self::MAX_WORDS);
            $title = implode(' ', $words);
        }
        if (mb_strlen($title) > self::MAX_CHARS) {
            $title = rtrim(mb_substr($title, 0, self::MAX_CHARS - 1)) . '…';
        }

        return $title !== '' ? $title : null;
    }
}
