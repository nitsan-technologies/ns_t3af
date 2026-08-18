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

namespace NITSAN\NsT3AF\AiLabel\Service;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Domain\LabellingMode;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Bulk confirm / clear / field updates with undo batch support.
 */
final class AiLabelBulkActionService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ConfirmationService $confirmationService,
        private readonly UndoCacheService $undoCacheService,
    ) {}

    /**
     * @param list<string> $refs table:uid pairs
     * @return array{processed: int, action: string}
     */
    public function execute(string $action, array $refs, int $backendUserId, string $payload = ''): array
    {
        $batch = [];
        $processed = 0;

        foreach ($this->parseRefs($refs) as ['table' => $table, 'uid' => $uid]) {
            $previous = $this->fetchUndoSnapshot($table, $uid);
            if ($previous === null) {
                continue;
            }

            $batch[] = ['table' => $table, 'uid' => $uid, 'values' => $previous];

            match ($action) {
                'confirm' => $this->confirmationService->confirm($table, $uid, $backendUserId),
                'clear' => $this->confirmationService->clearConfirmation($table, $uid),
                'no_ai' => $this->updateFields($table, $uid, [
                    'tx_nst3af_ailabel_involvement' => Involvement::NoAi->value,
                    'tx_nst3af_ailabel_confirmed_by' => $backendUserId,
                    'tx_nst3af_ailabel_confirmed_at' => time(),
                ]),
                'do_not_label' => $this->updateFields($table, $uid, [
                    'tx_nst3af_ailabel_labelling_mode' => LabellingMode::Never->value,
                    'tx_nst3af_ailabel_confirmed_by' => $backendUserId,
                    'tx_nst3af_ailabel_confirmed_at' => time(),
                ]),
                'set_public_interest' => $this->updateFields($table, $uid, [
                    'tx_nst3af_ailabel_public_interest' => 1,
                ]),
                'assign_responsible' => $this->updateFields($table, $uid, [
                    'tx_nst3af_ailabel_responsible_person' => $payload,
                    'tx_nst3af_ailabel_human_review' => 1,
                ]),
                default => null,
            };

            ++$processed;
        }

        if ($batch !== []) {
            $this->undoCacheService->rememberBulk($backendUserId, $batch);
        }

        return ['processed' => $processed, 'action' => $action];
    }

    public function undo(int $backendUserId): int
    {
        return $this->undoCacheService->restoreBulk($backendUserId);
    }

    /**
     * @param list<string> $refs
     * @return list<array{table: string, uid: int}>
     */
    private function parseRefs(array $refs): array
    {
        $parsed = [];
        foreach ($refs as $ref) {
            if (!str_contains($ref, ':')) {
                continue;
            }
            [$table, $uid] = explode(':', $ref, 2);
            $table = trim($table);
            $uid = (int) $uid;
            if ($table === '' || $uid <= 0) {
                continue;
            }
            $parsed[] = ['table' => $table, 'uid' => $uid];
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUndoSnapshot(string $table, int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable($table)
            ->select(['*'], $table, ['uid' => $uid])
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateFields(string $table, int $uid, array $fields): void
    {
        $this->connectionPool->getConnectionForTable($table)->update($table, $fields, ['uid' => $uid]);
    }
}
