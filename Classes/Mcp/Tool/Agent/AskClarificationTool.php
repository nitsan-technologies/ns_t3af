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
    #[McpTool(
        name: 'ask_clarification',
        description: 'Ask the editor a short clarifying question when the request is ambiguous.'
            . ' Pass the question in the editor language. Do not call write tools until answered.',
    )]
    public function execute(string $question): string
    {
        $question = trim($question);
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
        ], JSON_THROW_ON_ERROR);
    }
}
