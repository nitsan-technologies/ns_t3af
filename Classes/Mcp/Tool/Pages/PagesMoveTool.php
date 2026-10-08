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
use NITSAN\NsT3AF\Mcp\Attribute\McpToolIntent;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Helper\MoveTarget;
use NITSAN\NsT3AF\Mcp\Tool\Result\ErrorResult;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;

/**
 * Moves a page, including a hidden page. A hidden page is not deleted and can still be moved.
 */
#[McpToolSeverity(ToolSeverity::Write)]
#[McpToolIntent(
    verbs: ['move', 'place', 'put'],
    nouns: ['page', 'pages', 'position', 'tree'],
    modules: ['web_layout', 'web_list', 'records'],
    examples: [
        'Move this page before Page 2',
        'Put the page after Page 1',
        'Verschiebe die Seite vor Seite 2',
        'Seite nach Seite 1 verschieben',
    ],
    summary: 'Move a page to a new position in the page tree. beforeUid places it directly before that page. afterUid places it directly after that page. targetPid places it as the first child of that page.',
    category: 'pages',
)]
readonly class PagesMoveTool implements McpNonAiToolInterface, McpPlannableToolInterface
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
        $target = $this->resolveTarget($arguments);

        if ($uid <= 0) {
            throw new \InvalidArgumentException('Move requires uid > 0.');
        }
        if ($target === 0) {
            throw new \InvalidArgumentException('Move requires beforeUid, afterUid, targetPid, or a non-zero target.');
        }
        if ($this->recordService->findExistingUids('pages', [$uid]) === []) {
            throw new \InvalidArgumentException('Page not found: uid ' . $uid);
        }
        if ($target > 0) {
            $this->recordService->assertParentPageExists($target);
        } else {
            $this->recordService->assertInsertAfterExists('pages', abs($target));
        }

        $current = $this->recordService->findByUid('pages', $uid, ['title', 'pid']) ?? [];
        $title = trim((string) ($current['title'] ?? ''));

        return new ToolPlan('move', 'pages_move', [
            new ToolPlanField(
                ToolPlanField::buildKey('pages', $uid, '_move'),
                'pages',
                $uid,
                '_move',
                (string) ($current['pid'] ?? ''),
                $this->moveProposal($arguments, $target),
            ),
        ], [
            'target' => $target,
            'label' => $title !== '' ? $title : 'Page ' . $uid,
        ]);
    }

    #[McpTool(
        name: 'pages_move',
        description: 'Move an existing page to a new position in the page tree. A hidden page can be moved.'
            . ' Provide exactly one of beforeUid, afterUid, or targetPid.'
            . ' beforeUid places the page directly before that page.'
            . ' afterUid places the page directly after that page.'
            . ' targetPid places the page as the first child of that page.'
            . ' Do not use content_move for a page, and do not update the pid field.',
    )]
    public function execute(int $uid, int $beforeUid = 0, int $afterUid = 0, int $targetPid = -1, int $target = 0): string
    {
        $arguments = ['target' => $target];
        if ($beforeUid > 0) {
            $arguments['beforeUid'] = $beforeUid;
        }
        if ($afterUid > 0) {
            $arguments['afterUid'] = $afterUid;
        }
        if ($targetPid !== -1) {
            $arguments['targetPid'] = $targetPid;
        }
        $resolved = $this->resolveTarget($arguments);
        if ($uid <= 0 || $resolved === 0) {
            throw new \InvalidArgumentException('Move requires a page uid and beforeUid, afterUid, or targetPid.');
        }

        $this->dataHandlerService->moveRecord('pages', $uid, $resolved);

        return json_encode(['uid' => $uid, 'target' => $resolved, 'moved' => true], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveTarget(array $arguments): int
    {
        $beforeUid = (int) ($arguments['beforeUid'] ?? 0);
        if ($beforeUid > 0) {
            return $this->recordService->targetBeforePage($beforeUid, (int) ($arguments['uid'] ?? 0));
        }

        return self::resolveExplicitTarget($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function resolveExplicitTarget(array $arguments): int
    {
        $afterUid = (int) ($arguments['afterUid'] ?? 0);
        if ($afterUid > 0 || array_key_exists('targetPid', $arguments)) {
            $targetPid = array_key_exists('targetPid', $arguments) ? (int) $arguments['targetPid'] : -1;
            $resolved = MoveTarget::resolve($targetPid, $afterUid);
            if ($resolved instanceof ErrorResult) {
                throw new \InvalidArgumentException($resolved->error);
            }

            return $resolved;
        }

        return (int) ($arguments['target'] ?? 0);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function moveProposal(array $arguments, int $target): string
    {
        $beforeUid = (int) ($arguments['beforeUid'] ?? 0);
        if ($beforeUid > 0) {
            return 'before ' . $this->pageName($beforeUid);
        }

        $afterUid = (int) ($arguments['afterUid'] ?? 0);
        if ($afterUid > 0) {
            return 'after ' . $this->pageName($afterUid);
        }

        if (array_key_exists('targetPid', $arguments) && (int) $arguments['targetPid'] >= 0) {
            return 'first page inside ' . $this->pageName((int) $arguments['targetPid']);
        }

        return 'move to target ' . $target;
    }

    private function pageName(int $uid): string
    {
        $row = $this->recordService->findByUid('pages', $uid, ['title']) ?? [];
        $title = trim((string) ($row['title'] ?? ''));

        return ($title !== '' ? $title : 'Page ' . $uid) . ' [' . $uid . ']';
    }
}
