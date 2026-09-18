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

namespace NITSAN\NsT3AF\Mcp\Attribute;

/**
 * Hide a tool from the AI Agent catalog (NL + slash) while keeping it MCP-visible.
 *
 * DualMode tools without {@see \NITSAN\NsT3AF\Mcp\Contract\McpPreviewableToolInterface}
 * are hidden automatically; use this attribute for NonAi helpers that must stay
 * agent-hidden (e.g. t3aa_get_file_for_metadata).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class McpAgentHidden {}
