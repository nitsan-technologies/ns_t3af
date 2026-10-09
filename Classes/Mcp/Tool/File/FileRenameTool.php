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
use NITSAN\NsT3AF\Mcp\Contract\McpArgumentCheckInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpFalStorageToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\McpFalPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class FileRenameTool implements McpArgumentCheckInterface, McpFalStorageToolInterface, McpPlannableToolInterface
{
    public function __construct(
        private FileService $fileService,
        private McpFalPlanBuilder $falPlanBuilder,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function checkArguments(array $arguments): void
    {
        $storageUid = (int) ($arguments['storageUid'] ?? 1);
        $fileIdentifier = trim((string) ($arguments['fileIdentifier'] ?? ''));
        $newName = trim((string) ($arguments['newName'] ?? ''));

        if ($fileIdentifier === '' && $newName === '') {
            throw new \InvalidArgumentException(
                'Which file should be renamed, and what should the new name be? Example: /file_rename /user_upload/test.txt renamed.txt',
            );
        }
        if ($fileIdentifier === '') {
            throw new \InvalidArgumentException(
                'Which file should be renamed to "' . $newName . '"? Give the file path, for example /user_upload/test.txt.',
            );
        }
        if ($newName === '') {
            throw new \InvalidArgumentException(
                'What should the new name of "' . $fileIdentifier . '" be? The name must not contain a slash.',
            );
        }
        if ($this->nameIsInvalid($newName)) {
            throw new \InvalidArgumentException('The new name must be a single file name without a slash or backslash.');
        }

        try {
            $this->fileService->getFileInfo($storageUid, $fileIdentifier);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('That file was not found: ' . $fileIdentifier, 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $this->checkArguments($arguments);
        $storageUid = (int) ($arguments['storageUid'] ?? 1);
        $fileIdentifier = trim((string) ($arguments['fileIdentifier'] ?? ''));
        $newName = trim((string) ($arguments['newName'] ?? ''));

        return $this->falPlanBuilder->filePathChange(
            'rename',
            'file_rename',
            '_rename',
            $storageUid,
            $fileIdentifier,
            'rename to ' . $newName,
            ['newName' => $newName],
        );
    }

    #[McpTool(name: 'file_rename', description: 'Rename a file. Provide the file identifier and the new file name. Requires mcpAllowDestructiveFileOps.')]
    public function execute(string $fileIdentifier, string $newName, int $storageUid = 1): string
    {
        try {
            $this->fileService->renameFile($storageUid, $fileIdentifier, $newName);

            return json_encode(['fileIdentifier' => $fileIdentifier, 'newName' => $newName, 'renamed' => true], JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
        }
    }

    private function nameIsInvalid(string $newName): bool
    {
        return $newName === '.'
            || $newName === '..'
            || str_contains($newName, '/')
            || str_contains($newName, '\\');
    }
}
