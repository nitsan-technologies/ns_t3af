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

namespace NITSAN\NsT3AF\AiLabel\Hook;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Service\ApplicableTablesResolver;
use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use NITSAN\NsT3AF\AiLabel\Service\OriginRecorder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for origin recording and R1.5 validation (T3).
 */
final class DataHandlerAiLabelHook
{
    /** @var array<string, Involvement|null> */
    private array $previousInvolvement = [];

    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler,
    ): void {
        if (!$this->isApplicableTable($table) || !is_numeric($id)) {
            return;
        }

        $uid = (int) $id;
        if ($uid <= 0) {
            return;
        }

        $key = $table . ':' . $uid;
        $recorder = GeneralUtility::makeInstance(OriginRecorder::class);
        $connection = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getConnectionForTable($table);
        $value = $connection->select(
            ['tx_nst3af_ailabel_involvement'],
            $table,
            ['uid' => $uid],
        )->fetchOne();
        $this->previousInvolvement[$key] = is_string($value) ? Involvement::tryFrom($value) : null;

        $humanReview = (bool) ($incomingFieldArray['tx_nst3af_ailabel_human_review'] ?? false);
        $responsible = trim((string) ($incomingFieldArray['tx_nst3af_ailabel_responsible_person'] ?? ''));
        if ($humanReview && $responsible === '') {
            $dataHandler->log(
                $table,
                $uid,
                1,
                0,
                1,
                'AI Label: responsible person required when human review is enabled',
                0,
                ['tx_nst3af_ailabel_responsible_person'],
            );
            unset($incomingFieldArray['tx_nst3af_ailabel_human_review']);
        }
    }

    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if (!$this->isApplicableTable($table)) {
            return;
        }

        $uid = is_numeric($id) ? (int) $id : (int) ($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($uid <= 0) {
            return;
        }

        if (isset($fieldArray['tx_nst3af_ailabel_involvement'])) {
            $involvement = Involvement::tryFrom((string) $fieldArray['tx_nst3af_ailabel_involvement'])
                ?? Involvement::NotReviewed;
            $key = $table . ':' . $uid;
            GeneralUtility::makeInstance(OriginRecorder::class)->recordOrigin(
                $table,
                $uid,
                $involvement,
                'manual',
                previousInvolvement: $this->previousInvolvement[$key] ?? null,
            );
        }

        $confirmation = GeneralUtility::makeInstance(ConfirmationService::class);
        if (!$confirmation->isConfirmed($table, $uid)) {
            return;
        }

        $hashFieldPresent = array_key_exists('bodytext', $fieldArray)
            || array_key_exists('header', $fieldArray)
            || array_key_exists('title', $fieldArray);
        if ($hashFieldPresent && !isset($fieldArray['tx_nst3af_ailabel_confirmed_at'])) {
            $confirmation->clearConfirmation($table, $uid);
        }
    }

    private function isApplicableTable(string $table): bool
    {
        return GeneralUtility::makeInstance(ApplicableTablesResolver::class)->isApplicable($table);
    }
}
