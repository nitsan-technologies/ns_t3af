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

/**
 * A write tool that can check its arguments before the AI Agent shows a confirmation card.
 *
 * Without the check, a wrong field name ("hideOnMobile" instead of "widgetHideOnMobile") is
 * only noticed after the editor clicked "Execute" (or silently dropped). With it, the agent gets
 * the reason right away, corrects the call and the card shows what really gets saved.
 *
 * Only called for agent confirmations; the MCP server runs execute() as before.
 */
interface McpArgumentCheckInterface
{
    /**
     * @param array<string, mixed> $arguments the arguments the agent wants to run the tool with
     * @throws \InvalidArgumentException with a message for the model (what is wrong, what is allowed)
     */
    public function checkArguments(array $arguments): void;
}
