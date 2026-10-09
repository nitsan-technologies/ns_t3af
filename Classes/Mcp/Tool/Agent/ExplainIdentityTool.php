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
 * Always-on agent tool: a fixed, non-generated answer to "who are you / who built you" so the
 * model never guesses a vendor from its own training data.
 *
 * @internal
 */
#[McpToolSeverity(ToolSeverity::Read)]
#[McpToolIntent(
    verbs: ['explain', 'tell', 'ask'],
    nouns: ['you', 'agent', 'developer', 'creator', 'vendor', 'maker', 'identity', 'product', 'company'],
    modules: [],
    examples: [
        'Who are you?',
        'Who developed you?',
        'Who made this?',
        'What company built this?',
        'Wer hat dich entwickelt?',
        'Wer bist du?',
    ],
    summary: 'State who built the AI Agent and what product/company it is part of.',
    category: 'general',
)]
final readonly class ExplainIdentityTool implements McpNonAiToolInterface
{
    #[McpTool(
        name: 'explain_identity',
        description: 'Return the fixed vendor/product identity of the AI Agent. Call this when the user asks who you are, '
            . 'who built or developed you, or what company/product this is — never answer from general knowledge.',
    )]
    public function execute(): string
    {
        return json_encode([
            'ok' => true,
            'summary' => 'I am the AI Agent built into AI Foundation for TYPO3 (EXT:ns_t3af), developed by '
                . 'T3Planet / NITSAN Technologies. I am not affiliated with the TYPO3 community or the TYPO3 '
                . 'Association, and I am not the underlying AI model itself — I use one, configured per provider by '
                . 'your administrator, to help you in this backend.',
        ], JSON_THROW_ON_ERROR);
    }
}
