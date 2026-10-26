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

/**
 * Builds workspace UID enums and human-readable labels for MCP tool schemas.
 */
readonly class McpWorkspaceEnumResolver
{
    public function __construct(private WorkspaceListService $workspaceListService) {}

    /**
     * @return list<int>
     */
    public function resolveEnum(): array
    {
        return array_map(
            static fn(array $workspace): int => (int) $workspace['uid'],
            $this->workspaceListService->list(),
        );
    }

    public function buildDescription(): string
    {
        return 'Optional workspace UID for this call only. Omit to use the workspace selected in the MCP Server backend module. '
            . 'Allowed values are listed in the enum; call workspace_list for titles and details.';
    }
}
