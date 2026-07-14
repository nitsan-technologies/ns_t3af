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

namespace NITSAN\NsT3AF\Mcp\Tool\Helper;

use NITSAN\NsT3AF\Mcp\Tool\Result\ErrorResult;

/**
 * Translates the user-facing (targetPid, afterUid) pair into the integer
 * convention used by TYPO3 DataHandler's `move` / `copy` commands:
 *   - positive value => destination page id (place at top)
 *   - negative value => -(uid) of the record to place AFTER (same page/column as sibling).
 *
 * @internal
 */
class MoveTarget
{
    public static function resolve(int $targetPid, int $afterUid): int|ErrorResult
    {
        $hasPid = $targetPid >= 0;
        $hasAfter = $afterUid > 0;

        if ($hasPid && $hasAfter) {
            return new ErrorResult('Provide exactly one of targetPid or afterUid, not both.');
        }

        if (!$hasPid && !$hasAfter) {
            return new ErrorResult('Provide either targetPid (>= 0) or afterUid (> 0).');
        }

        return $hasAfter ? -$afterUid : $targetPid;
    }
}
