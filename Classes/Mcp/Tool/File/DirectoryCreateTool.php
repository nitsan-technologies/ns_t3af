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
use NITSAN\NsT3AF\Mcp\Service\McpFalPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class DirectoryCreateTool implements McpFalStorageToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private FileService $fileService,
        private McpFalPlanBuilder $falPlanBuilder,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $storageUid = (int) ($arguments['storageUid'] ?? 1);
        $directoryName = (string) ($arguments['directoryName'] ?? '');
        $parentPath = (string) ($arguments['parentPath'] ?? '/');

        return $this->falPlanBuilder->directoryPathChange(
            'create',
            'directory_create',
            '_create',
            $storageUid,
            $parentPath,
            'create ' . $directoryName . ' in ' . $parentPath,
            ['directoryName' => $directoryName, 'parentPath' => $parentPath],
        );
    }

    #[McpTool(name: 'directory_create', description: 'Create a new directory in a storage.')]
    public function execute(string $directoryName, string $parentPath = '/', int $storageUid = 1): string
    {
        return json_encode(
            $this->fileService->createDirectory($storageUid, $parentPath, $directoryName),
            JSON_THROW_ON_ERROR,
        );
    }
}
