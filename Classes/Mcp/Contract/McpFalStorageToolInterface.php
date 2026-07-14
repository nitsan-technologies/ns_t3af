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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Contract;

/**
 * Marker for MCP tools that operate on FAL storage (fileadmin) only.
 *
 * TYPO3 workspaces do not version FAL files or directories; such tools must not
 * expose the global workspaceId parameter in their published schema.
 *
 * @api Semver-stable registration contract for third-party MCP tools.
 */
interface McpFalStorageToolInterface extends McpNonAiToolInterface {}
