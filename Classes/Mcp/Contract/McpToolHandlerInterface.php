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
 * Marker for MCP tool handlers auto-registered by {@see \NITSAN\NsT3AF\DependencyInjection\McpCapabilityCompilerPass}.
 *
 * Implement this interface in any extension, register the class as a DI service, and
 * annotate the {@code execute()} method with {@see \Mcp\Capability\Attribute\McpTool}.
 * The handler is collected via the {@code mcp.tool} tag and loaded by
 * {@see \NITSAN\NsT3AF\Mcp\Server\McpServerFactory} on the next container rebuild.
 *
 * Alternatively, apply {@see \NITSAN\NsT3AF\Mcp\Attribute\AsMcpTool} on the class
 * without implementing this interface.
 *
 * @api Semver-stable registration contract for third-party MCP tools.
 */
interface McpToolHandlerInterface {}
