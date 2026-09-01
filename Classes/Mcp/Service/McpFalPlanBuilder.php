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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;

/**
 * Builds FAL-aware tool plans with before/after path reporting.
 *
 * @internal
 */
final class McpFalPlanBuilder
{
    public function __construct(private readonly FileService $fileService) {}

    /**
     * @param array<string, mixed> $context
     */
    public function filePathChange(
        string $action,
        string $toolName,
        string $pseudoField,
        int $storageUid,
        string $fileIdentifier,
        string $proposedDescription,
        array $context = [],
    ): ToolPlan {
        $currentPath = $fileIdentifier;
        try {
            $info = $this->fileService->getFileInfo($storageUid, $fileIdentifier);
            $name = is_string($info['name'] ?? null) ? $info['name'] : '';
            $folder = is_string($info['folder'] ?? null) ? $info['folder'] : '';
            if ($name !== '') {
                $currentPath = rtrim($folder, '/') . '/' . ltrim($name, '/');
            }
        } catch (\Throwable) {
            // Keep identifier as current value when file cannot be resolved.
        }

        return new ToolPlan($action, $toolName, [
            new ToolPlanField(
                ToolPlanField::buildKey('sys_file', 0, $pseudoField),
                'sys_file',
                0,
                $pseudoField,
                $currentPath,
                $proposedDescription,
            ),
        ], array_merge(['storageUid' => $storageUid, 'fileIdentifier' => $fileIdentifier], $context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function directoryPathChange(
        string $action,
        string $toolName,
        string $pseudoField,
        int $storageUid,
        string $directoryPath,
        string $proposedDescription,
        array $context = [],
    ): ToolPlan {
        return new ToolPlan($action, $toolName, [
            new ToolPlanField(
                ToolPlanField::buildKey('sys_file', 0, $pseudoField),
                'sys_file',
                0,
                $pseudoField,
                $directoryPath,
                $proposedDescription,
            ),
        ], array_merge(['storageUid' => $storageUid, 'directoryPath' => $directoryPath], $context));
    }
}
