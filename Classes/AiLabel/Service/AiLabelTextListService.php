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

use NITSAN\NsT3AF\AiLabel\Dto\AiLabelFilters;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Paginated text review list (pages, content, applicable extension tables).
 *
 * Streams projected columns and keeps only the current page window in memory
 * (plus a small top-N buffer for cross-table crdate ordering).
 */
final class AiLabelTextListService
{
    private const CORE_TABLES = ['tt_content', 'pages'];

    /**
     * @var list<string>
     */
    private const PROJECTION = [
        'uid',
        'pid',
        'crdate',
        'title',
        'header',
        'tx_nst3af_ailabel_confirmed_at',
        'tx_nst3af_ailabel_confirmed_by',
        'tx_nst3af_ailabel_involvement',
        'tx_nst3af_ailabel_labelling_mode',
        'tx_nst3af_ailabel_recording_source',
        'tx_nst3af_ailabel_public_interest',
        'tx_nst3af_ailabel_human_review',
        'tx_nst3af_ailabel_responsible_person',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AiLabelRecordEvaluator $evaluator,
        private readonly ApplicableTablesResolver $applicableTablesResolver,
    ) {}

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   pagination: array{page: int, max: int, totalPages: int, start: int, end: int}
     * }
     */
    public function list(AiLabelFilters $filters): array
    {
        $offset = ($filters->page - 1) * $filters->max;
        $keep = $offset + $filters->max;
        $buffer = [];
        $total = 0;

        foreach ($this->textTables() as $table) {
            foreach ($this->iterateProjectedRows($table) as $record) {
                $normalized = $this->normalizeTextRow($table, $record);
                if (!$this->rowMatchesFilters($normalized, $filters)) {
                    continue;
                }
                ++$total;
                $this->pushNewest($buffer, $normalized, $keep);
            }
        }

        usort(
            $buffer,
            static fn(array $a, array $b): int => ($b['created'] ?? 0) <=> ($a['created'] ?? 0),
        );
        $pageRows = array_slice($buffer, $offset, $filters->max);
        $totalPages = max(1, (int) ceil($total / max(1, $filters->max)));

        return [
            'rows' => $pageRows,
            'total' => $total,
            'pagination' => [
                'page' => $filters->page,
                'max' => $filters->max,
                'totalPages' => $totalPages,
                'start' => $total > 0 ? $offset + 1 : 0,
                'end' => min($offset + $filters->max, $total),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function textTables(): array
    {
        $tables = [];
        foreach ($this->applicableTablesResolver->getTables() as $table) {
            if ($table === 'sys_file_metadata' || in_array($table, $tables, true)) {
                continue;
            }
            $tables[] = $table;
        }

        return $tables !== [] ? $tables : self::CORE_TABLES;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function iterateProjectedRows(string $table): \Generator
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return;
        }

        $available = [];
        foreach ($connection->createSchemaManager()->listTableColumns($table) as $column) {
            $available[$column->getName()] = true;
        }

        $select = [];
        foreach (self::PROJECTION as $column) {
            if (isset($available[$column])) {
                $select[] = $column;
            }
        }
        if ($select === [] || !isset($available['uid'])) {
            return;
        }

        $qb = $connection->createQueryBuilder();
        $qb->select(...$select)->from($table);
        if (isset($available['crdate'])) {
            $qb->orderBy('crdate', 'DESC');
        }

        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            yield $row;
        }
    }

    /**
     * Keep only the newest {@see $keep} rows by `created` (bounded memory).
     *
     * @param list<array<string, mixed>> $buffer
     * @param array<string, mixed> $row
     */
    private function pushNewest(array &$buffer, array $row, int $keep): void
    {
        if ($keep <= 0) {
            return;
        }

        if (count($buffer) < $keep) {
            $buffer[] = $row;

            return;
        }

        $oldestKept = null;
        foreach ($buffer as $existing) {
            $created = (int) ($existing['created'] ?? 0);
            if ($oldestKept === null || $created < $oldestKept) {
                $oldestKept = $created;
            }
        }
        if ((int) ($row['created'] ?? 0) <= (int) $oldestKept) {
            return;
        }

        $buffer[] = $row;
        usort(
            $buffer,
            static fn(array $a, array $b): int => ($b['created'] ?? 0) <=> ($a['created'] ?? 0),
        );
        // Keep reference: do not reassign $buffer (breaks &$buffer).
        if (count($buffer) > $keep) {
            array_splice($buffer, $keep);
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeTextRow(string $table, array $record): array
    {
        $decision = $this->evaluator->decide($table, $record);
        $confirmedBy = (int) ($record['tx_nst3af_ailabel_confirmed_by'] ?? 0);
        $humanReview = (bool) ($record['tx_nst3af_ailabel_human_review'] ?? false);
        $responsible = trim((string) ($record['tx_nst3af_ailabel_responsible_person'] ?? ''));

        $reviewedLabel = '-';
        if ($humanReview && $responsible !== '') {
            $reviewedLabel = $responsible;
        } elseif ($humanReview) {
            $reviewedLabel = 'reviewed, nobody named';
        }

        return [
            'table' => $table,
            'uid' => (int) ($record['uid'] ?? 0),
            'title' => $this->resolveTitle($table, $record),
            'type' => $this->resolveType($table),
            'involvement' => $this->evaluator->involvement($record)->value,
            'publicInterest' => (bool) ($record['tx_nst3af_ailabel_public_interest'] ?? false) ? 'yes' : 'no',
            'reviewedLabel' => $reviewedLabel,
            'unnamedReview' => $this->evaluator->hasUnnamedReview($record),
            'noticeState' => $this->evaluator->labelStateKey($table, $record),
            'reasonCode' => $decision->reasonCode->value,
            'created' => (int) ($record['crdate'] ?? 0),
            'confirmedBy' => $confirmedBy,
            'recordingSource' => (string) ($record['tx_nst3af_ailabel_recording_source'] ?? ''),
            'awaitingReview' => $this->evaluator->isAwaitingReview($record),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveTitle(string $table, array $record): string
    {
        return match ($table) {
            'pages' => $this->nonEmptyString($record['title'] ?? null)
                ?? ('Page #' . (int) ($record['uid'] ?? 0)),
            'tt_content' => $this->nonEmptyString($record['header'] ?? null)
                ?? sprintf(
                    'Page %d · Content %d',
                    (int) ($record['pid'] ?? 0),
                    (int) ($record['uid'] ?? 0),
                ),
            default => $table . ' #' . ($record['uid'] ?? ''),
        };
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolveType(string $table): string
    {
        return match ($table) {
            'pages' => 'page',
            'tt_content' => 'content',
            default => 'record',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMatchesFilters(array $row, AiLabelFilters $filters): bool
    {
        if ($filters->search !== '') {
            $haystack = strtolower($row['title'] . ' ' . $row['reviewedLabel'] . ' ' . $row['recordingSource']);
            if (!str_contains($haystack, strtolower($filters->search))) {
                return false;
            }
        }
        if ($filters->involvement !== '' && $row['involvement'] !== $filters->involvement) {
            return false;
        }
        if ($filters->labelState !== '' && $row['noticeState'] !== $filters->labelState) {
            return false;
        }
        if ($filters->publicInterest === 'yes' && $row['publicInterest'] !== 'yes') {
            return false;
        }
        if ($filters->publicInterest === 'no' && $row['publicInterest'] !== 'no') {
            return false;
        }
        if ($filters->confirmedBy === 'none' && $row['confirmedBy'] > 0) {
            return false;
        }
        if ($filters->confirmedBy !== '' && $filters->confirmedBy !== 'none'
            && (string) $row['confirmedBy'] !== $filters->confirmedBy) {
            return false;
        }
        if ($filters->recordingSource !== '' && !str_contains($row['recordingSource'], $filters->recordingSource)) {
            return false;
        }
        if ($filters->reasonCode !== '' && $row['reasonCode'] !== $filters->reasonCode) {
            return false;
        }
        if ($filters->dateFrom !== '') {
            $from = strtotime($filters->dateFrom . ' 00:00:00');
            if ($from !== false && (int) $row['created'] < $from) {
                return false;
            }
        }
        if ($filters->dateTo !== '') {
            $to = strtotime($filters->dateTo . ' 23:59:59');
            if ($to !== false && (int) $row['created'] > $to) {
                return false;
            }
        }

        return true;
    }
}
