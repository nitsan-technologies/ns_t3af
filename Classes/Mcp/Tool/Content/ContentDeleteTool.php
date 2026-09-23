<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

namespace NITSAN\NsT3AF\Mcp\Tool\Content;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolIntent;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

/**
 * Soft-delete a tt_content record via DataHandler (draft confirmation in the agent).
 *
 * @internal
 */
#[McpToolSeverity(ToolSeverity::Destructive)]
#[McpToolIntent(
    verbs: ['delete', 'remove'],
    nouns: ['content', 'element', 'tt_content', 'ce'],
    modules: ['web_layout', 'web_list', 'records'],
    examples: [
        'Delete content element #20',
        'Remove tt_content 312',
        'Delete this content element',
    ],
    summary: 'Delete a content element by uid (requires editor confirmation before apply).',
    category: 'content',
)]
readonly class ContentDeleteTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    private const TABLE = 'tt_content';

    public function __construct(
        private DataHandlerService $dataHandlerService,
        private McpRecordPlanService $recordPlanService,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        return $this->recordPlanService->planDelete(
            self::TABLE,
            (int) ($arguments['uid'] ?? 0),
            'content_delete',
        );
    }

    #[McpTool(
        name: 'content_delete',
        description: 'Delete a content element (tt_content) by its uid.'
            . ' Pass the content element uid (not the page id).'
            . ' The agent shows a confirmation draft before anything is deleted.'
            . ' Do not use content_move for deletion.',
    )]
    public function execute(int $uid): string
    {
        if ($uid <= 0) {
            return json_encode(['error' => 'Delete requires uid > 0'], JSON_THROW_ON_ERROR);
        }

        $this->dataHandlerService->deleteRecord(self::TABLE, $uid);

        return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
