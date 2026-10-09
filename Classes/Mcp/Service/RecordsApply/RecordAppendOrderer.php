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
 * Makes new records land at the END of their page, in the order they were sent.
 *
 * DataHandler puts a new record with a positive pid at the TOP of the page. A client sending
 * First, Second, Third would get Third, Second, First. For every new record whose pid is a page
 * (a uid or a NEW id), this rewrites the pid to a negative "after record X" reference:
 *
 *  - the first new record of a group goes after the last record already on that page,
 *  - every further one goes after the new record sent just before it.
 *
 * A group is table + pid + column + language, so content in different columns or languages is
 * ordered independently. A negative pid is the client's explicit placement and stays as it is.
 * Tables without a manual sort order (no ctrl.sortby) and inline child tables (hideTable) are
 * ordered by their parent's field list, so they are not touched here.
 */
readonly class RecordAppendOrderer
{
    public function __construct(private LastRecordLocator $locator) {}

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    public function apply(array $datamap): array
    {
        foreach ($datamap as $table => $records) {
            $sortBy = $GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? '';
            if (!is_string($sortBy) || $sortBy === '' || ($GLOBALS['TCA'][$table]['ctrl']['hideTable'] ?? false)) {
                continue;
            }

            /** @var array<string, string> $lastNewInGroup group key => NEW id */
            $lastNewInGroup = [];
            foreach ($records as $id => $fields) {
                if (!str_starts_with((string) $id, 'NEW') || !isset($fields['pid'])) {
                    continue;
                }

                $pid = (string) $fields['pid'];
                if (str_starts_with($pid, '-')) {
                    continue;
                }

                $key = implode('|', [
                    $pid,
                    (string) ($fields['colPos'] ?? ''),
                    (string) ($fields['sys_language_uid'] ?? '0'),
                ]);

                if (isset($lastNewInGroup[$key])) {
                    $datamap[$table][$id]['pid'] = '-' . $lastNewInGroup[$key];
                } elseif (ctype_digit($pid)) {
                    $last = $this->locator->lastUid($table, (int) $pid, $fields);
                    if ($last !== null) {
                        $datamap[$table][$id]['pid'] = '-' . $last;
                    }
                }

                $lastNewInGroup[$key] = (string) $id;
            }
        }

        return $datamap;
    }
}
