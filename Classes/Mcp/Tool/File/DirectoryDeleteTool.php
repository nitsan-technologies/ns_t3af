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

namespace NITSAN\NsT3AF\Mcp\Tool\File;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

#[McpToolSeverity(ToolSeverity::Destructive)]
readonly class DirectoryDeleteTool implements McpFalStorageToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private FileService $fileService,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $storageUid = (int) ($arguments['storageUid'] ?? 1);
        $directoryIdentifier = (string) ($arguments['directoryIdentifier'] ?? '');
        $recursive = (bool) ($arguments['recursive'] ?? false);

        $proposed = $recursive
            ? 'delete recursively (including contents)'
            : 'delete';

        return $this->confirmationPlanBuilder->confirmation(
            'delete',
            'directory_delete',
            '_delete',
            $directoryIdentifier,
            $proposed,
            [
                'storageUid' => $storageUid,
                'directoryIdentifier' => $directoryIdentifier,
                'recursive' => $recursive,
            ],
        );
    }

    #[McpTool(
        name: 'directory_delete',
        description: 'Delete a directory from a storage. Set recursive to true for non-empty directories.',
    )]
    public function execute(string $directoryIdentifier, bool $recursive = false, int $storageUid = 1): string
    {
        $this->fileService->deleteDirectory($storageUid, $directoryIdentifier, $recursive);

        return json_encode(
            ['directoryIdentifier' => $directoryIdentifier, 'recursive' => $recursive, 'deleted' => true],
            JSON_THROW_ON_ERROR,
        );
    }
}
