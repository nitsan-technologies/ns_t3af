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
use NITSAN\NsT3AF\Service\AiLogService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * R3 confirmation with version binding.
 */
final class ConfirmationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ?AiLogService $aiLogService = null,
    ) {}

    public function confirm(string $table, int $uid, int $backendUserId, string $contentFingerprint = ''): void
    {
        $now = time();
        $hash = $contentFingerprint !== '' ? hash('sha256', $contentFingerprint) : $this->computeVersionHash($table, $uid);

        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            [
                'tx_nst3af_ailabel_confirmed_by' => $backendUserId,
                'tx_nst3af_ailabel_confirmed_at' => $now,
                'tx_nst3af_ailabel_version_hash' => $hash,
            ],
            ['uid' => $uid],
        );

        $this->aiLogService?->writeLog(
            'Label confirmed',
            'info',
            'ailabel',
            ['table' => $table, 'uid' => $uid, 'by' => $backendUserId],
        );

        $row = $this->connectionPool->getConnectionForTable($table)
            ->select(['tx_nst3af_ailabel_involvement'], $table, ['uid' => $uid])
            ->fetchAssociative();
        $involvement = Involvement::tryFrom((string) ($row['tx_nst3af_ailabel_involvement'] ?? ''))
            ?? Involvement::NotReviewed;
        GeneralUtility::makeInstance(AiLabelInteropService::class)
            ->reportAfterConfirmation($table, $uid, $involvement);
    }

    public function clearConfirmation(string $table, int $uid): void
    {
        $previous = $this->connectionPool->getConnectionForTable($table)
            ->select(
                [
                    'tx_nst3af_ailabel_confirmed_by',
                    'tx_nst3af_ailabel_confirmed_at',
                    'tx_nst3af_ailabel_version_hash',
                ],
                $table,
                ['uid' => $uid],
            )->fetchAssociative();
        if (is_array($previous)) {
            GeneralUtility::makeInstance(UndoCacheService::class)->remember($table, $uid, $previous);
        }

        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            [
                'tx_nst3af_ailabel_confirmed_by' => 0,
                'tx_nst3af_ailabel_confirmed_at' => 0,
                'tx_nst3af_ailabel_version_hash' => '',
            ],
            ['uid' => $uid],
        );
    }

    public function isConfirmed(string $table, int $uid): bool
    {
        $row = $this->connectionPool->getConnectionForTable($table)
            ->select(
                ['tx_nst3af_ailabel_confirmed_at', 'tx_nst3af_ailabel_version_hash'],
                $table,
                ['uid' => $uid],
            )->fetchAssociative();

        return is_array($row) && (int) ($row['tx_nst3af_ailabel_confirmed_at'] ?? 0) > 0;
    }

    private function computeVersionHash(string $table, int $uid): string
    {
        $row = $this->connectionPool->getConnectionForTable($table)
            ->select(['*'], $table, ['uid' => $uid])
            ->fetchAssociative();

        if (!is_array($row)) {
            return '';
        }

        unset($row['tx_nst3af_ailabel_confirmed_by'], $row['tx_nst3af_ailabel_confirmed_at'], $row['tx_nst3af_ailabel_version_hash']);

        return hash('sha256', serialize($row));
    }
}
