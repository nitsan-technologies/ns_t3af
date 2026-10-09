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

namespace NITSAN\NsT3AF\Mcp\Tool\Workspace;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceVersionService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

#[McpToolSeverity(ToolSeverity::Destructive)]
readonly class WorkspaceDiscardTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private WorkspaceVersionService $workspaceVersionService,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $table = (string) ($arguments['table'] ?? '');
        $workspaceVersionUid = (int) ($arguments['workspaceVersionUid'] ?? 0);

        if ($table === '') {
            throw new \InvalidArgumentException('table is required.');
        }

        if ($workspaceVersionUid <= 0) {
            throw new \InvalidArgumentException('workspaceVersionUid must be > 0.');
        }

        $row = $this->workspaceVersionService->loadVersionRow($table, $workspaceVersionUid);
        if ($row === null) {
            throw new \InvalidArgumentException(
                'Workspace version not found: ' . $table . ' uid ' . $workspaceVersionUid,
            );
        }

        return $this->confirmationPlanBuilder->confirmation(
            'delete',
            'workspace_discard',
            '_discard',
            'exists',
            'discard workspace version',
            [
                'table' => $table,
                'workspaceVersionUid' => $workspaceVersionUid,
            ],
            $table,
            $workspaceVersionUid,
        );
    }

    #[McpTool(
        name: 'workspace_discard',
        description: 'Discard a workspace version, dropping unpublished changes. Pass table and workspaceVersionUid.'
            . ' The live record (if any) is unaffected. Requires cms-workspaces extension.',
    )]
    public function execute(string $table, int $workspaceVersionUid): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return json_encode(['error' => 'cms-workspaces extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $row = $this->workspaceVersionService->loadVersionRow($table, $workspaceVersionUid);
        if ($row === null) {
            return json_encode(
                ['error' => 'Workspace version not found', 'table' => $table, 'uid' => $workspaceVersionUid],
                JSON_THROW_ON_ERROR,
            );
        }

        $this->dataHandlerService->processCommand([
            $table => [
                $workspaceVersionUid => [
                    'version' => ['action' => 'clearWSID'],
                ],
            ],
        ]);

        return json_encode(['discarded' => true, 'table' => $table, 'uid' => $workspaceVersionUid], JSON_THROW_ON_ERROR);
    }
}
