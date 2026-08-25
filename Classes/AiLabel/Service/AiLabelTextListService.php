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
 */
final class AiLabelTextListService
{
    private const CORE_TABLES = ['tt_content', 'pages'];

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
        $rows = [];
        foreach ($this->textTables() as $table) {
            foreach ($this->fetchTableRows($table) as $record) {
                $rows[] = $this->normalizeTextRow($table, $record);
            }
        }

        usort($rows, static fn(array $a, array $b): int => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        $rows = $this->applyFilters($rows, $filters);
        $total = count($rows);
        $offset = ($filters->page - 1) * $filters->max;
        $pageRows = array_slice($rows, $offset, $filters->max);
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
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $rows, AiLabelFilters $filters): array
    {
        return array_values(array_filter($rows, function (array $row) use ($filters): bool {
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
        }));
    }
}
