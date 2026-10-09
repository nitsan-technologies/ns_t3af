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
 * Apply must re-invoke the tool ({@see planKind} = tool_confirmation). Writing the
 * pseudo-field through DataHandler is a no-op (e.g. file_reference_add would report
 * "Applied" without creating sys_file_reference rows).
 *
 * @internal
 */
final class McpConfirmationPlanBuilder
{
    /** Same value as Agent SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION. */
    public const PLAN_KIND_TOOL_CONFIRMATION = 'tool_confirmation';

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
        $arguments = is_array($context['arguments'] ?? null) ? $context['arguments'] : null;
        if ($arguments === null) {
            $arguments = $context;
            unset($arguments['planKind'], $arguments['summary'], $arguments['displayArguments'], $arguments['arguments']);
        }

        $context['planKind'] = self::PLAN_KIND_TOOL_CONFIRMATION;
        $context['arguments'] = $arguments;
        if (!isset($context['summary']) || trim((string) $context['summary']) === '') {
            $context['summary'] = $proposedDescription;
        }

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
