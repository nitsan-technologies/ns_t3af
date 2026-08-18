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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * FAL folder tree with total and open (awaiting review) counts.
 */
final class AiLabelFolderTreeService
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly AiLabelRecordEvaluator $evaluator,
    ) {}

    /**
     * @return array{
     *   storageUid: int,
     *   root: array{identifier: string, label: string, totalFiles: int, openCount: int, active: bool},
     *   folders: list<array{identifier: string, label: string, totalFiles: int, openCount: int, active: bool}>
     * }
     */
    public function buildTree(string $activeFolderIdentifier = ''): array
    {
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            return ['storageUid' => 0, 'root' => $this->emptyNode('/', 'fileadmin/', false), 'folders' => []];
        }

        $rootFolder = $storage->getRootLevelFolder();
        $rootIdentifier = $rootFolder->getIdentifier();
        $displayRoot = 'fileadmin/';

        $rootCounts = $this->countFilesInFolder($storage->getUid(), $rootIdentifier, true);
        $folders = [];
        foreach ($rootFolder->getSubfolders() as $subfolder) {
            $identifier = $subfolder->getIdentifier();
            $counts = $this->countFilesInFolder($storage->getUid(), $identifier, false);
            $folders[] = [
                'identifier' => $identifier,
                'label' => rtrim($subfolder->getName(), '/') . '/',
                'totalFiles' => $counts['total'],
                'openCount' => $counts['open'],
                'active' => $this->isActiveFolder($activeFolderIdentifier, $identifier),
            ];
        }

        usort($folders, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        if ($activeFolderIdentifier === '') {
            $activeFolderIdentifier = $folders[0]['identifier'] ?? $rootIdentifier;
        }

        return [
            'storageUid' => $storage->getUid(),
            'root' => [
                'identifier' => $rootIdentifier,
                'label' => $displayRoot,
                'totalFiles' => $rootCounts['total'],
                'openCount' => $rootCounts['open'],
                'active' => $this->isActiveFolder($activeFolderIdentifier, $rootIdentifier),
            ],
            'folders' => array_map(
                static fn(array $folder): array => array_merge($folder, [
                    'active' => $folder['identifier'] === $activeFolderIdentifier
                        || rtrim($folder['identifier'], '/') === rtrim($activeFolderIdentifier, '/'),
                ]),
                $folders,
            ),
        ];
    }

    public function resolveActiveFolder(string $requestedFolder): string
    {
        $tree = $this->buildTree($requestedFolder);
        foreach ($tree['folders'] as $folder) {
            if ($folder['active']) {
                return $folder['identifier'];
            }
        }

        return $tree['root']['identifier'];
    }

    /**
     * @return array{total: int, open: int}
     */
    public function countFilesInFolder(int $storageUid, string $folderIdentifier, bool $recursive): array
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file');
        $qb = $connection->createQueryBuilder();
        $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $prefix = $folderIdentifier === '/' ? '/' : rtrim($folderIdentifier, '/') . '/';
        if ($recursive) {
            $qb->andWhere(
                $qb->expr()->or(
                    $qb->expr()->eq('identifier', $qb->createNamedParameter($folderIdentifier)),
                    $qb->expr()->like(
                        'identifier',
                        $qb->createNamedParameter($this->escapeLike($prefix) . '%', Connection::PARAM_STR),
                    ),
                ),
            );
        } else {
            $qb->andWhere(
                $qb->expr()->like(
                    'identifier',
                    $qb->createNamedParameter($this->escapeLike($prefix) . '%', Connection::PARAM_STR),
                ),
            );
            $qb->andWhere(
                $qb->expr()->notLike(
                    'identifier',
                    $qb->createNamedParameter($this->escapeLike($prefix) . '%/%', Connection::PARAM_STR),
                ),
            );
        }

        $qb->andWhere($qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, Connection::PARAM_INT)));
        $qb->select('uid')->from('sys_file');
        $fileUids = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
        $total = count($fileUids);
        if ($total === 0) {
            return ['total' => 0, 'open' => 0];
        }

        $metaQb = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $metaQb->getRestrictions()->removeAll();
        $metaQb->select('*')
            ->from('sys_file_metadata')
            ->where($metaQb->expr()->in('file', $metaQb->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)));

        $open = 0;
        foreach ($metaQb->executeQuery()->fetchAllAssociative() as $row) {
            if ($this->evaluator->isAwaitingReview($row)) {
                ++$open;
            }
        }

        return ['total' => $total, 'open' => $open];
    }

    /**
     * @return array{identifier: string, label: string, totalFiles: int, openCount: int, active: bool}
     */
    private function emptyNode(string $identifier, string $label, bool $active): array
    {
        return [
            'identifier' => $identifier,
            'label' => $label,
            'totalFiles' => 0,
            'openCount' => 0,
            'active' => $active,
        ];
    }

    private function isActiveFolder(string $active, string $candidate): bool
    {
        if ($active === '') {
            return false;
        }

        return rtrim($active, '/') === rtrim($candidate, '/');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
