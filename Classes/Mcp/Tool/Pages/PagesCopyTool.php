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

namespace NITSAN\NsT3AF\Mcp\Tool\Pages;

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
readonly class PagesCopyTool implements McpNonAiToolInterface, McpPlannableToolInterface
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
        $includeSubpages = (bool) ($arguments['includeSubpages'] ?? false);

        if ($uid <= 0) {
            throw new \InvalidArgumentException('Copy requires uid > 0.');
        }

        if ($this->recordService->findExistingUids('pages', [$uid]) === []) {
            throw new \InvalidArgumentException('Page not found: uid ' . $uid);
        }

        $currentTitle = $this->recordService->findByUid('pages', $uid, ['title'])['title'] ?? ('Page ' . $uid);

        return new ToolPlan('copy', 'pages_copy', [
            new ToolPlanField(
                ToolPlanField::buildKey('pages', $uid, '_copy'),
                'pages',
                $uid,
                '_copy',
                (string) $currentTitle,
                'copy to target ' . $target . ($includeSubpages ? ' (with subpages)' : ''),
            ),
        ], [
            'target' => $target,
            'copyTreeDepth' => $includeSubpages ? 99 : 0,
        ]);
    }

    #[McpTool(
        name: 'pages_copy',
        description: 'Copy a page to a new position in the page tree.'
            . ' Use a positive target to copy as a child of that page (target = parent pid).'
            . ' Use a negative target to copy after a specific page (target = -uid of the page to place after).'
            . ' Set includeSubpages to true to copy the entire subtree including all subpages.',
    )]
    public function execute(int $uid, int $target, bool $includeSubpages = false): string
    {
        $newUid = $this->dataHandlerService->copyRecord('pages', $uid, $target, $includeSubpages ? 99 : 0);

        return json_encode(['sourceUid' => $uid, 'newUid' => $newUid], JSON_THROW_ON_ERROR);
    }
}
