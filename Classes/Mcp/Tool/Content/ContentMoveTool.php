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
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class ContentMoveTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private RecordService $recordService,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $uid = (int) ($arguments['uid'] ?? 0);
        $target = (int) ($arguments['target'] ?? 0);

        if ($uid <= 0) {
            throw new \InvalidArgumentException('Move requires uid > 0.');
        }

        if ($this->recordService->findExistingUids('tt_content', [$uid]) === []) {
            throw new \InvalidArgumentException('Content element not found: uid ' . $uid);
        }

        $current = $this->recordService->findByUid('tt_content', $uid, ['header', 'pid']) ?? [];
        $label = trim((string) ($current['header'] ?? '')) !== '' ? (string) $current['header'] : 'Content ' . $uid;

        return new ToolPlan('move', 'content_move', [
            new ToolPlanField(
                ToolPlanField::buildKey('tt_content', $uid, '_move'),
                'tt_content',
                $uid,
                '_move',
                (string) ($current['pid'] ?? ''),
                'move to target ' . $target,
            ),
        ], [
            'target' => $target,
            'label' => $label,
        ]);
    }

    #[McpTool(
        name: 'content_move',
        description: 'Move a content element to a new position.'
            . ' Use a positive target to move to the top of a page (target = page pid).'
            . ' Use a negative target to move after another content element (target = -uid of the element to place after).',
    )]
    public function execute(int $uid, int $target): string
    {
        $this->dataHandlerService->moveRecord('tt_content', $uid, $target);

        return json_encode(['uid' => $uid, 'target' => $target, 'moved' => true], JSON_THROW_ON_ERROR);
    }
}
