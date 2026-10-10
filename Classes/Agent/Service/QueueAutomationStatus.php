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

namespace NITSAN\NsT3AF\Agent\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Whether an enabled scheduler task will drain the SEO or translation queue.
 *
 * A disabled or deleted task does not count. This does not change the scheduler list.
 *
 * @internal
 */
final readonly class QueueAutomationStatus
{
    /**
     * @var list<string>
     */
    private const SEO_TASK_TYPES = [
        'NITSAN\\NsT3Ai\\Task\\BulkMassSeoOptimizeTask',
        't3af:bulk:seo-optimize',
        'nst3ai:bulk:seo-optimize',
    ];

    /**
     * @var list<string>
     */
    private const TRANSLATION_TASK_TYPES = [
        'NITSAN\\NsT3Ai\\Task\\BulkPageTranslateTask',
        't3af:bulk:translate',
        'nst3ai:bulk:translate',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<string>
     */
    public function promptLines(): array
    {
        $flags = $this->flags();

        return self::linesFor($flags['seo'], $flags['translation']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{seo: bool, translation: bool}
     */
    public static function flagsFromRows(array $rows): array
    {
        $seo = false;
        $translation = false;
        foreach ($rows as $row) {
            if ((int) ($row['deleted'] ?? 0) === 1 || (int) ($row['disable'] ?? 0) === 1) {
                continue;
            }
            $taskType = trim((string) ($row['tasktype'] ?? ''));
            if (self::matches($taskType, self::SEO_TASK_TYPES)) {
                $seo = true;
            }
            if (self::matches($taskType, self::TRANSLATION_TASK_TYPES)) {
                $translation = true;
            }
        }

        return ['seo' => $seo, 'translation' => $translation];
    }

    /**
     * @return list<string>
     */
    public static function linesFor(bool $seoEnabled, bool $translationEnabled): array
    {
        return [
            $seoEnabled
                ? 'Automatic processing of the SEO queue is on. When the editor asks when queued Google texts will be written, say they are written the next time that processing runs.'
                : 'Automatic processing of the SEO queue is off. When the editor asks when queued Google texts will be written, say an admin has to turn on automatic processing first by enabling the SEO scheduler task, and offer to write the texts now. Do not say a scheduled job will run.',
            $translationEnabled
                ? 'Automatic processing of the translation queue is on. When the editor asks when queued translations will be written, say they are written the next time that processing runs.'
                : 'Automatic processing of the translation queue is off. When the editor asks when queued translations will be written, say an admin has to turn on automatic processing first by enabling the translation scheduler task, and offer to translate now. Do not say a scheduled job will run.',
            'When the editor asks to show the SEO queue, call t3ai_mass_seo_queue_list again. When they ask to show the translation queue, call t3ai_mass_translation_queue_list again. Do not repeat an earlier list. The queue can change after automatic processing runs.',
        ];
    }

    /**
     * @return array{seo: bool, translation: bool}
     */
    private function flags(): array
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable('tx_scheduler_task');
            $schema = $connection->createSchemaManager();
            if (!$schema->tablesExist(['tx_scheduler_task'])) {
                return ['seo' => false, 'translation' => false];
            }
            $columns = array_change_key_case($schema->listTableColumns('tx_scheduler_task'));
            if (!isset($columns['tasktype'])) {
                return ['seo' => false, 'translation' => false];
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_scheduler_task');
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder
                ->select('tasktype', 'disable', 'deleted')
                ->from('tx_scheduler_task')
                ->executeQuery()
                ->fetchAllAssociative();

            return self::flagsFromRows($rows);
        } catch (\Throwable) {
            return ['seo' => false, 'translation' => false];
        }
    }

    /**
     * @param list<string> $known
     */
    private static function matches(string $taskType, array $known): bool
    {
        foreach ($known as $candidate) {
            if (strcasecmp($taskType, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }
}
