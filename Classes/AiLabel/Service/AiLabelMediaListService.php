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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Paginated media review list scoped to a FAL folder.
 */
final class AiLabelMediaListService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
        private readonly AiLabelRecordEvaluator $evaluator,
        private readonly AiLabelFolderTreeService $folderTreeService,
    ) {}

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   pagination: array{page: int, max: int, totalPages: int, start: int, end: int},
     *   folder: array{identifier: string, label: string, totalFiles: int, openCount: int},
     *   spotlight: array<string, mixed>|null
     * }
     */
    public function list(AiLabelFilters $filters): array
    {
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            return $this->emptyResult($filters);
        }

        $folderIdentifier = $this->folderTreeService->resolveActiveFolder($filters->folder);
        $folderCounts = $this->folderTreeService->countFilesInFolder($storage->getUid(), $folderIdentifier, false);
        $folderLabel = $folderIdentifier === '/' ? 'fileadmin/' : 'fileadmin' . $folderIdentifier;

        $rows = $this->fetchRows($storage->getUid(), $folderIdentifier);
        $rows = $this->applyFilters($rows, $filters);
        $total = count($rows);
        $offset = ($filters->page - 1) * $filters->max;
        $pageRows = array_slice($rows, $offset, $filters->max);

        $spotlight = null;
        foreach ($rows as $row) {
            if ($this->evaluator->isAwaitingReview($row)) {
                $spotlight = $row;
                break;
            }
        }

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
            'folder' => [
                'identifier' => $folderIdentifier,
                'label' => $folderLabel,
                'totalFiles' => $folderCounts['total'],
                'openCount' => $folderCounts['open'],
            ],
            'spotlight' => $spotlight,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(int $storageUid, string $folderIdentifier): array
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file');
        $qb = $connection->createQueryBuilder();
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $prefix = $folderIdentifier === '/' ? '/' : rtrim($folderIdentifier, '/') . '/';
        $qb->select('f.uid AS file_uid', 'f.name', 'f.identifier', 'f.mime_type', 'f.creation_date AS file_crdate', 'm.*')
            ->from('sys_file', 'f')
            ->leftJoin(
                'f',
                'sys_file_metadata',
                'm',
                $qb->expr()->eq('m.file', $qb->quoteIdentifier('f.uid')),
            )
            ->where($qb->expr()->eq('f.storage', $qb->createNamedParameter($storageUid, Connection::PARAM_INT)))
            ->andWhere(
                $qb->expr()->like(
                    'f.identifier',
                    $qb->createNamedParameter($this->escapeLike($prefix) . '%', Connection::PARAM_STR),
                ),
            )
            ->andWhere(
                $qb->expr()->notLike(
                    'f.identifier',
                    $qb->createNamedParameter($this->escapeLike($prefix) . '%/%', Connection::PARAM_STR),
                ),
            )
            ->orderBy('f.creation_date', 'DESC');

        $rows = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $metaUid = (int) ($row['uid'] ?? 0);
            if ($metaUid <= 0) {
                continue;
            }
            $rows[] = $this->normalizeMediaRow($row);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeMediaRow(array $row): array
    {
        $table = 'sys_file_metadata';
        $meta = $row;
        $meta['uid'] = (int) ($row['uid'] ?? 0);
        $decision = $this->evaluator->decide($table, $meta);
        $confirmedBy = (int) ($meta['tx_nst3af_ailabel_confirmed_by'] ?? 0);

        return [
            'table' => $table,
            'uid' => $meta['uid'],
            'fileUid' => (int) ($row['file_uid'] ?? 0),
            'fileName' => (string) ($row['name'] ?? ''),
            'kind' => $this->resolveKind((string) ($row['mime_type'] ?? '')),
            'involvement' => $this->evaluator->involvement($meta)->value,
            'labelState' => $this->evaluator->labelStateKey($table, $meta),
            'reasonCode' => $decision->reasonCode->value,
            'created' => (int) ($row['file_crdate'] ?? $meta['crdate'] ?? 0),
            'confirmedBy' => $confirmedBy,
            'confirmedByLabel' => $this->backendUserLabel($confirmedBy, (int) ($meta['tx_nst3af_ailabel_confirmed_at'] ?? 0)),
            'recordingSource' => (string) ($meta['tx_nst3af_ailabel_recording_source'] ?? ''),
            'aiSystem' => (string) ($meta['tx_nst3af_ailabel_ai_system'] ?? ''),
            'awaitingReview' => $this->evaluator->isAwaitingReview($meta),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $rows, AiLabelFilters $filters): array
    {
        return array_values(array_filter($rows, function (array $row) use ($filters): bool {
            if ($filters->search !== '') {
                $haystack = strtolower($row['fileName'] . ' ' . $row['aiSystem'] . ' ' . $row['recordingSource']);
                if (!str_contains($haystack, strtolower($filters->search))) {
                    return false;
                }
            }
            if ($filters->involvement !== '' && $row['involvement'] !== $filters->involvement) {
                return false;
            }
            if ($filters->labelState !== '' && $row['labelState'] !== $filters->labelState) {
                return false;
            }
            if ($filters->fileType !== '' && $row['kind'] !== $filters->fileType) {
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

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total: int,
     *   pagination: array{page: int, max: int, totalPages: int, start: int, end: int},
     *   folder: array{identifier: string, label: string, totalFiles: int, openCount: int},
     *   spotlight: null
     * }
     */
    private function emptyResult(AiLabelFilters $filters): array
    {
        return [
            'rows' => [],
            'total' => 0,
            'pagination' => [
                'page' => 1,
                'max' => $filters->max,
                'totalPages' => 1,
                'start' => 0,
                'end' => 0,
            ],
            'folder' => [
                'identifier' => '/',
                'label' => 'fileadmin/',
                'totalFiles' => 0,
                'openCount' => 0,
            ],
            'spotlight' => null,
        ];
    }

    private function resolveKind(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'video/') => 'video',
            default => 'file',
        };
    }

    private function backendUserLabel(int $uid, int $confirmedAt): string
    {
        if ($uid <= 0 || $confirmedAt <= 0) {
            return '';
        }

        $record = BackendUtility::getRecord('be_users', $uid);
        if (!is_array($record)) {
            return '';
        }

        $name = trim((string) ($record['realName'] ?? $record['username'] ?? ''));
        if ($name === '') {
            return '';
        }

        return $name . ' · ' . date('j M', $confirmedAt);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
