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

namespace NITSAN\NsT3AF\Mcp\Tool\Agent;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolIntent;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;

/**
 * Always-on agent tool: ask the editor a clarifying question (no write).
 *
 * @internal
 */
#[McpToolSeverity(ToolSeverity::Read)]
#[McpToolIntent(
    verbs: ['ask', 'clarify', 'confirm'],
    nouns: ['question', 'clarification', 'detail'],
    modules: ['web_layout', 'records', 'file', 'media_management'],
    examples: [
        'Ask which SEO fields they want',
        'Clarify the target page',
        'Nachfragen welche Felder gemeint sind',
    ],
    summary: 'Ask the backend editor a short clarifying question before acting.',
    category: 'general',
)]
final readonly class AskClarificationTool implements McpNonAiToolInterface
{
    /**
     * @param string $question The question, in the editor's language
     * @param list<string> $options Short answer choices shown as buttons (2-6), e.g. ["German", "French"]
     */
    #[McpTool(
        name: 'ask_clarification',
        description: 'Ask the editor a short clarifying question when the request is ambiguous or a required choice is missing.'
            . ' Pass the question in the editor language and, when the answer is one of a few choices'
            . ' (target language, which page), the choices as options. Do not call write tools until answered.',
    )]
    public function execute(string $question, array $options = []): string
    {
        [$question, $options] = self::liftInlineOptions(trim($question), $options);
        $options = self::normalizeOptions($options);
        if ($question === '') {
            return json_encode([
                'ok' => false,
                'error' => 'question is required',
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'ok' => true,
            'summary' => $question,
            'clarification' => $question,
            'options' => $options,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Models sometimes paste the choices into the question as a JSON list (["A", "B"]) and send no
     * options. The list becomes buttons and is removed from the sentence.
     * They also dump the whole ask_clarification payload as text:
     * {"question":"…","options":["A","B"]} — lift that into a real clarification.
     *
     * @param  array<mixed>                 $options
     * @return array{0: string, 1: array<mixed>}
     */
    public static function liftInlineOptions(string $question, array $options): array
    {
        $embedded = self::parseEmbeddedClarification($question);
        if ($embedded !== null) {
            return [
                $embedded['question'],
                $options !== [] ? $options : $embedded['options'],
            ];
        }

        // An empty list pasted into the question ("[]I need the language…") is noise, not a choice.
        $question = trim((string) preg_replace('/\[\s*\]/u', '', $question));
        if ($options !== [] || preg_match('/\[\s*"[^\]]*\]/u', $question, $match) !== 1) {
            return [$question, $options];
        }
        $decoded = json_decode($match[0], true);
        if (!is_array($decoded) || $decoded === [] || !array_is_list($decoded)) {
            return [$question, $options];
        }
        $question = trim((string) preg_replace('/\s*:?\s*' . preg_quote($match[0], '/') . '/u', '', $question));

        return [$question, $decoded];
    }

    /**
     * When the model writes ask_clarification as JSON in the reply instead of calling the tool.
     *
     * @return array{question: string, options: list<mixed>, remainder: string}|null
     */
    public static function parseEmbeddedClarification(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || !str_contains($text, '"question"')) {
            return null;
        }

        if (preg_match('/\{[^{}]*"question"\s*:\s*"(?:\\\\.|[^"\\\\])*"\s*,\s*"options"\s*:\s*\[[^\]]*\]\s*\}/su', $text, $match) !== 1
            && preg_match('/\{[^{}]*"options"\s*:\s*\[[^\]]*\]\s*,\s*"question"\s*:\s*"(?:\\\\.|[^"\\\\])*"\s*\}/su', $text, $match) !== 1
        ) {
            return null;
        }

        try {
            $decoded = json_decode($match[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $question = trim((string) ($decoded['question'] ?? ''));
        $options = is_array($decoded['options'] ?? null) ? array_values($decoded['options']) : [];
        if ($question === '' || $options === []) {
            return null;
        }

        $remainder = trim(str_replace($match[0], '', $text));

        return [
            'question' => $question,
            'options' => $options,
            'remainder' => $remainder,
        ];
    }

    /**
     * @param array<mixed> $options
     * @return list<string>
     */
    public static function normalizeOptions(array $options): array
    {
        $clean = [];
        foreach ($options as $option) {
            $label = is_scalar($option) ? trim((string) $option) : '';
            if ($label !== '' && !in_array($label, $clean, true)) {
                $clean[] = mb_substr($label, 0, 80);
            }
        }

        return array_slice($clean, 0, 6);
    }
}
