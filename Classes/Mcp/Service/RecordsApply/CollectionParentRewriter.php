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
 * DataHandler substitutes NEW ids in relation fields only. A Content Blocks child points at its
 * parent through a passthrough integer (foreign_table_parent_uid), so "NEWce" is stored as 0.
 * These updates fix that after the batch has real uids. A plain integer, including 0, is left as sent.
 */
readonly class CollectionParentRewriter
{
    private const NEW_ID = '/^NEW[A-Za-z0-9]+$/';

    public function __construct(private InlineForeignFields $foreignFields = new InlineForeignFields()) {}

    /**
     * @param array<mixed> $datamap
     * @param array<string, int> $created NEW id => uid from this batch
     * @return list<array{table: string, uid: int, field: string, value: int}>
     */
    public function updates(array $datamap, array $created): array
    {
        $updates = [];
        foreach ($datamap as $table => $records) {
            if (!is_string($table) || !is_array($records)) {
                continue;
            }
            $parentFields = $this->foreignFields->ofChildTable($table);
            if ($parentFields === []) {
                continue;
            }
            foreach ($records as $id => $fields) {
                if (!is_string($id) || preg_match(self::NEW_ID, $id) !== 1 || !is_array($fields)) {
                    continue;
                }
                $childUid = $created[$id] ?? 0;
                if ($childUid <= 0) {
                    continue;
                }
                foreach ($parentFields as $field) {
                    if (!array_key_exists($field, $fields)) {
                        continue;
                    }
                    $parentUid = self::batchParentUid($fields[$field], $created);
                    if ($parentUid === null) {
                        continue;
                    }
                    $updates[] = [
                        'table' => $table,
                        'uid' => $childUid,
                        'field' => $field,
                        'value' => $parentUid,
                    ];
                }
            }
        }

        return $updates;
    }

    /** @param array<string, int> $created */
    private static function batchParentUid(mixed $value, array $created): ?int
    {
        if (!is_string($value) || preg_match(self::NEW_ID, $value) !== 1) {
            return null;
        }
        $parentUid = $created[$value] ?? 0;

        return $parentUid > 0 ? $parentUid : null;
    }
}
