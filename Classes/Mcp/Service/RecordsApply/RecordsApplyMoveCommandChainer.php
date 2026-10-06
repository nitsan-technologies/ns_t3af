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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

/**
 * Builds DataHandler move commands that keep the request order on the target page.
 *
 * A positive pid places a record at the TOP of that page, so moving several records to the same
 * pid with the same target would reverse their order. For tables with ctrl.sortby the first uid
 * keeps the given target and each following one moves after the previous uid in the list.
 * Tables without a sort field keep the historical "every uid gets the same target" behaviour.
 */
final class RecordsApplyMoveCommandChainer
{
    /** Whether DataHandler can place a record after another via a negative move target. */
    public static function tableSupportsSorting(string $table): bool
    {
        $sortBy = $GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? '';

        return is_string($sortBy) && $sortBy !== '';
    }

    /**
     * @param list<int> $uids request order (must already be distinct positive uids)
     * @return array<int, array{move: int}>
     */
    public static function chain(string $table, array $uids, int $target): array
    {
        if ($uids === []) {
            return [];
        }

        if (!self::tableSupportsSorting($table)) {
            $commands = [];
            foreach ($uids as $uid) {
                $commands[$uid] = ['move' => $target];
            }

            return $commands;
        }

        $commands = [];
        $previousUid = null;
        foreach ($uids as $uid) {
            $commands[$uid] = ['move' => $previousUid === null ? $target : -$previousUid];
            $previousUid = $uid;
        }

        return $commands;
    }
}
