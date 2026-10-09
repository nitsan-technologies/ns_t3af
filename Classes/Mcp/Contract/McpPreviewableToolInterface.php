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

namespace NITSAN\NsT3AF\Mcp\Contract;

use NITSAN\NsT3AF\Mcp\Dto\PreviewResult;

/**
 * DualMode write tools that can generate editor-facing suggestions without persisting.
 *
 * The AI Agent calls {@see preview()} under a native-mode override, shows a
 * suggestions card, then applies via context-mode content params. Never
 * generate-and-apply blindly from the agent.
 *
 * @api Semver-stable registration contract for third-party MCP tools.
 */
interface McpPreviewableToolInterface extends McpDualModeContentToolInterface
{
    /**
     * Generate variants without writing to the database.
     *
     * @param array<string, mixed> $arguments Tool targeting args (no content params required)
     * @param int                  $variants  Requested variant count (clamped 1–5 by the tool)
     */
    public function preview(array $arguments, int $variants = 1): PreviewResult;
}
