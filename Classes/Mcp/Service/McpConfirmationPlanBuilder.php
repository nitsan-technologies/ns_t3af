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
 * Builds confirmation-style tool plans when no TCA field diff exists.
 *
 * @internal
 */
final class McpConfirmationPlanBuilder
{
    /**
     * @param array<string, mixed> $context
     */
    public function confirmation(
        string $action,
        string $toolName,
        string $pseudoField,
        mixed $currentValue,
        string $proposedDescription,
        array $context = [],
        string $table = '_action',
        int $uid = 0,
    ): ToolPlan {
        return new ToolPlan($action, $toolName, [
            new ToolPlanField(
                ToolPlanField::buildKey($table, $uid, $pseudoField),
                $table,
                $uid,
                $pseudoField,
                $currentValue,
                $proposedDescription,
            ),
        ], $context);
    }
}
