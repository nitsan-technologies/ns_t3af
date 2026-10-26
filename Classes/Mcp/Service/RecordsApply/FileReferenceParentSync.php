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

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * After records_apply creates sys_file_reference rows directly, uid_foreign may be fixed in a
 * follow-up pass but the parent FAL counter and sys_refindex are not — unlike file_reference_add.
 */
readonly class FileReferenceParentSync
{
    private const NEW_ID = '/^NEW[A-Za-z0-9]+$/';

    public function __construct(private DataHandlerService $dataHandlerService) {}

    /**
     * @param array<mixed> $datamap
     * @param array<string, int> $created NEW id => uid from this batch
     */
    public function syncAfterBatch(array $datamap, array $created): void
    {
        $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
        $parents = [];

        $records = $datamap['sys_file_reference'] ?? null;
        if (!is_array($records)) {
            return;
        }

        foreach ($records as $id => $fields) {
            if (!is_array($fields)) {
                continue;
            }

            $refUid = 0;
            if (is_string($id) && preg_match(self::NEW_ID, $id) === 1) {
                $refUid = $created[$id] ?? 0;
            } elseif (is_int($id) && $id > 0) {
                $refUid = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $refUid = (int) $id;
            }

            if ($refUid > 0) {
                $referenceIndex->updateRefIndexTable('sys_file_reference', $refUid);
            }

            $table = is_string($fields['tablenames'] ?? null) ? (string) $fields['tablenames'] : '';
            $field = is_string($fields['fieldname'] ?? null) ? (string) $fields['fieldname'] : '';
            $parentUid = self::resolveForeignUid($fields['uid_foreign'] ?? null, $created);
            if ($table === '' || $field === '' || $parentUid <= 0) {
                continue;
            }

            $parents[$table . ':' . $parentUid . ':' . $field] = [
                'table' => $table,
                'uid' => $parentUid,
                'field' => $field,
            ];
        }

        foreach ($parents as $parent) {
            $this->dataHandlerService->syncFileFieldCounter($parent['table'], $parent['uid'], $parent['field']);
            $referenceIndex->updateRefIndexTable($parent['table'], $parent['uid']);
        }
    }

    /** @param array<string, int> $created */
    private static function resolveForeignUid(mixed $foreign, array $created): int
    {
        if (is_string($foreign) && preg_match(self::NEW_ID, $foreign) === 1) {
            return $created[$foreign] ?? 0;
        }
        if (is_int($foreign)) {
            return $foreign > 0 ? $foreign : 0;
        }
        if (is_string($foreign) && ctype_digit($foreign)) {
            return (int) $foreign;
        }

        return 0;
    }
}
