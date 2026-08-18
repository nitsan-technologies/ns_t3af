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

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Aggregated scoreboard and domain breakdown for the AI Label module.
 */
final class AiLabelStatisticsService
{
    private const MEDIA_TABLE = 'sys_file_metadata';
    private const TEXT_TABLES = ['tt_content', 'pages'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AiLabelRecordEvaluator $evaluator,
        private readonly OriginRecorder $originRecorder,
        private readonly ApplicableTablesResolver $applicableTablesResolver,
    ) {}

    /**
     * @return array{
     *   global: array{confirmed: int, awaiting: int, labelled: int, notLabelled: int},
     *   media: array{confirmed: int, awaiting: int, labelled: int, notLabelled: int, open: int, total: int, percent: int},
     *   texts: array{confirmed: int, awaiting: int, noticeShown: int, unnamedReview: int, open: int, total: int, percent: int},
     *   origins: array<string, int>,
     *   coverageBadges: array{done: int, needsPerson: int, blocked: int, total: int}
     * }
     */
    public function compute(): array
    {
        $global = ['confirmed' => 0, 'awaiting' => 0, 'labelled' => 0, 'notLabelled' => 0];
        $media = ['confirmed' => 0, 'awaiting' => 0, 'labelled' => 0, 'notLabelled' => 0, 'open' => 0, 'total' => 0, 'percent' => 0];
        $texts = ['confirmed' => 0, 'awaiting' => 0, 'labelled' => 0, 'notLabelled' => 0, 'noticeShown' => 0, 'unnamedReview' => 0, 'open' => 0, 'total' => 0, 'percent' => 0];
        $origins = [
            'ai_universe' => 0,
            'detected_upload' => 0,
            'editor' => 0,
            'extension' => 0,
        ];

        foreach ($this->fetchAllMediaRows() as $record) {
            $delta = $this->tally(self::MEDIA_TABLE, $record);
            $global = $this->addCounters($global, $delta);
            $media['confirmed'] += $delta['confirmed'];
            $media['awaiting'] += $delta['awaiting'];
            $media['labelled'] += $delta['labelled'];
            $media['notLabelled'] += $delta['notLabelled'];
            $this->accumulateOrigin($origins, $record);
            ++$media['total'];
        }

        foreach ($this->textTables() as $table) {
            foreach ($this->fetchTableRows($table) as $record) {
                $delta = $this->tally($table, $record);
                $global = $this->addCounters($global, $delta);
                $texts['confirmed'] += $delta['confirmed'];
                $texts['awaiting'] += $delta['awaiting'];
                $texts['labelled'] += $delta['labelled'];
                $texts['notLabelled'] += $delta['notLabelled'];
                $this->accumulateOrigin($origins, $record);
                ++$texts['total'];
                if ($this->evaluator->hasUnnamedReview($record)) {
                    ++$texts['unnamedReview'];
                }
                if ($this->evaluator->decide($table, $record)->showLabel) {
                    ++$texts['noticeShown'];
                }
            }
        }

        $media['open'] = $media['awaiting'];
        $texts['open'] = $texts['awaiting'];
        $media['percent'] = $this->percent($media['confirmed'], $media['total']);
        $texts['percent'] = $this->percent($texts['confirmed'], $texts['total']);

        $unbound = count($this->originRecorder->listUnboundGenerations());

        return [
            'global' => $global,
            'media' => $media,
            'texts' => $texts,
            'origins' => $origins,
            'coverageBadges' => [
                'done' => $global['confirmed'],
                'needsPerson' => $global['awaiting'],
                'blocked' => $unbound,
                'total' => max(1, $global['confirmed'] + $global['awaiting'] + $unbound),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{confirmed: int, awaiting: int, labelled: int, notLabelled: int}
     */
    private function tally(string $table, array $record): array
    {
        $confirmed = $this->evaluator->isConfirmed($record);
        $awaiting = $this->evaluator->isAwaitingReview($record);
        $showLabel = $this->evaluator->decide($table, $record)->showLabel;

        return [
            'confirmed' => $confirmed ? 1 : 0,
            'awaiting' => $awaiting ? 1 : 0,
            'labelled' => $showLabel ? 1 : 0,
            'notLabelled' => $showLabel ? 0 : 1,
        ];
    }

    /**
     * @param array{confirmed: int, awaiting: int, labelled: int, notLabelled: int} $counters
     * @param array{confirmed: int, awaiting: int, labelled: int, notLabelled: int} $delta
     * @return array{confirmed: int, awaiting: int, labelled: int, notLabelled: int}
     */
    private function addCounters(array $counters, array $delta): array
    {
        $counters['confirmed'] += $delta['confirmed'];
        $counters['awaiting'] += $delta['awaiting'];
        $counters['labelled'] += $delta['labelled'];
        $counters['notLabelled'] += $delta['notLabelled'];

        return $counters;
    }

    /**
     * @param array<string, int> $origins
     * @param array<string, mixed> $record
     */
    private function accumulateOrigin(array &$origins, array $record): void
    {
        $source = (string) ($record['tx_nst3af_ailabel_recording_source'] ?? '');
        if ($source === '') {
            return;
        }

        $bucket = match (true) {
            str_starts_with($source, 'detected') => 'detected_upload',
            str_starts_with($source, 'editor') => 'editor',
            str_starts_with($source, 'ns_') || str_starts_with($source, 't3') => 'ai_universe',
            default => 'extension',
        };
        ++$origins[$bucket];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllMediaRows(): array
    {
        return $this->fetchTableRows(self::MEDIA_TABLE);
    }

    /**
     * @return list<string>
     */
    private function textTables(): array
    {
        $tables = [];
        foreach ($this->applicableTablesResolver->getTables() as $table) {
            if ($table === self::MEDIA_TABLE || in_array($table, $tables, true)) {
                continue;
            }
            $tables[] = $table;
        }

        return $tables !== [] ? $tables : self::TEXT_TABLES;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTableRows(string $table): array
    {
        if (!$this->connectionPool->getConnectionForTable($table)->createSchemaManager()->tablesExist([$table])) {
            return [];
        }

        return $this->connectionPool->getConnectionForTable($table)
            ->select(['*'], $table)
            ->fetchAllAssociative();
    }

    private function percent(int $confirmed, int $total): int
    {
        if ($total === 0) {
            return 0;
        }

        return (int) round(($confirmed / $total) * 100);
    }
}
