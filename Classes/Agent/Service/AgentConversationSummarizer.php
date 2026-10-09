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

/**
 * "Summarize conversation": one short summary of everything so far, stored as a message of
 * type `summary`. From then on the agent replays the summary plus the newer messages instead
 * of the whole transcript ({@see AgentPromptBuilder::buildHistory()}), which keeps long
 * conversations cheap and within the model's context.
 *
 * Runs with the conversation's provider through the normal AI service (governance, credits,
 * request log).
 *
 * @internal
 */
final readonly class AgentConversationSummarizer
{
    /** Transcript characters sent for summarizing (≈ 10k tokens). */
    private const TRANSCRIPT_CHARS = 40000;

    /** A conversation needs at least this many messages after the last summary. */
    public const MIN_MESSAGES = 4;

    public function __construct(
        private AiServiceInterface $aiService,
        private AgentPromptBuilder $promptBuilder,
        private AgentLanguageResolver $languageResolver,
    ) {}

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function canSummarize(array $messages): bool
    {
        $since = 0;
        foreach ($messages as $message) {
            $type = (string) ($message['meta']['type'] ?? '');
            if ($type === 'summary') {
                $since = 0;
                continue;
            }
            if (($message['meta']['hidden'] ?? false) !== true) {
                ++$since;
            }
        }

        return $since >= self::MIN_MESSAGES;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    public function summarize(array $messages, string $providerIdentifier = '', int $pageId = 0): array
    {
        $lines = [];
        foreach ($this->promptBuilder->buildHistory($messages, $pageId, self::TRANSCRIPT_CHARS) as $entry) {
            $lines[] = ($entry['role'] === 'user' ? 'Editor: ' : 'Agent: ') . $entry['content'];
        }
        if ($lines === []) {
            throw new \RuntimeException('Nothing to summarize.');
        }

        $prompt = implode("\n", [
            'Summarize this conversation between a TYPO3 backend editor and the AI Agent so the agent can continue it later without the full transcript.',
            'Keep: what the editor wants, the pages, records and files involved (with their names and ids), changes that were applied or declined, open questions and the next step.',
            'Leave out greetings and anything already finished that does not matter any more.',
            'At most 10 short lines, plain text, each line starting with "- ".',
            $this->languageResolver->backendLanguageInstruction(),
            '',
            'Conversation:',
            implode("\n", $lines),
        ]);

        $response = $this->aiService->complete($prompt, new AiOptions(
            // '' and 'default' (the select's "site default" entry) mean: let AiService pick the default provider.
            providerIdentifier: in_array($providerIdentifier, ['', AgentProviderOptions::DEFAULT], true) ? null : $providerIdentifier,
            temperature: 0.2,
            maxTokens: 600,
            extensionKey: 'ns_t3af',
            featureKey: 'agent.conversation_summary',
            featureLabel: 'AI Agent conversation summary',
            requestSource: 'agent',
            pageId: $pageId > 0 ? $pageId : null,
            extra: ['skipBrandContext' => true],
        ));
        $summary = trim($response->content);
        if ($summary === '') {
            throw new \RuntimeException('The AI provider returned an empty summary.');
        }

        return [
            'role' => 'assistant',
            'content' => $summary,
            'meta' => [
                'type' => 'summary',
                'coversMessages' => count($messages),
                'createdAt' => time(),
            ],
        ];
    }
}
