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
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Nested FAL folder tree with total and open (awaiting review) counts.
 */
final class AiLabelFolderTreeService
{
    /** @var list<string> */
    private const IGNORE_DIRECTORIES = [
        '_processed_',
    ];

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly AiLabelRecordEvaluator $evaluator,
    ) {}

    /**
     * @return array{
     *   storageUid: int,
     *   root: array{identifier: string, label: string, totalFiles: int, openCount: int, active: bool, expanded: bool, children: list},
     *   folders: list<array{identifier: string, label: string, totalFiles: int, openCount: int, active: bool, expanded: bool, children: list}>
     * }
     */
    public function buildTree(string $activeFolderIdentifier = ''): array
    {
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            return [
                'storageUid' => 0,
                'root' => $this->emptyNode('/', 'fileadmin/', false, false),
                'folders' => [],
            ];
        }

        $rootFolder = $storage->getRootLevelFolder();
        $rootIdentifier = $rootFolder->getIdentifier();
        $active = $this->resolveActiveFolder($activeFolderIdentifier);
        $folders = $this->buildChildren($storage->getUid(), $rootFolder, $active);
        $rootCounts = $this->countFilesInFolder($storage->getUid(), $rootIdentifier, true);

        return [
            'storageUid' => $storage->getUid(),
            'root' => [
                'identifier' => $rootIdentifier,
                'label' => 'fileadmin/',
                'totalFiles' => $rootCounts['total'],
                'openCount' => $rootCounts['open'],
                'active' => self::isSameFolder($active, $rootIdentifier),
                'expanded' => true,
                'children' => $folders,
            ],
            'folders' => $folders,
        ];
    }

    public function resolveActiveFolder(string $requestedFolder): string
    {
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            return '/';
        }

        $rootFolder = $storage->getRootLevelFolder();
        $rootIdentifier = $rootFolder->getIdentifier();
        $requested = trim($requestedFolder);

        if ($requested === '') {
            return $this->firstBrowsableFolderIdentifier($rootFolder) ?? $rootIdentifier;
        }

        $normalized = self::normalizeIdentifierStatic($requested);
        if (self::isSameFolder($normalized, $rootIdentifier)) {
            return $rootIdentifier;
        }

        try {
            $folder = $storage->getFolder($normalized);

            return $folder->getIdentifier();
        } catch (\Throwable) {
            return $this->firstBrowsableFolderIdentifier($rootFolder) ?? $rootIdentifier;
        }
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

    public static function isSameFolder(string $a, string $b): bool
    {
        return self::normalizeIdentifierStatic($a) === self::normalizeIdentifierStatic($b);
    }

    /**
     * True when $ancestor is the same as or a parent path of $descendant.
     */
    public static function isAncestorOrSelf(string $ancestor, string $descendant): bool
    {
        $ancestor = rtrim(self::normalizeIdentifierStatic($ancestor), '/');
        $descendant = rtrim(self::normalizeIdentifierStatic($descendant), '/');
        if ($ancestor === '' || $ancestor === $descendant) {
            return true;
        }

        return str_starts_with($descendant, $ancestor . '/');
    }

    /**
     * @param list<array{identifier: string, children?: list}> $folders
     * @return list<array{identifier: string, active: bool, expanded: bool, children: list}>
     */
    public static function markActiveAndExpanded(array $folders, string $activeIdentifier): array
    {
        $activeIdentifier = self::normalizeIdentifierStatic($activeIdentifier);
        $marked = [];
        foreach ($folders as $folder) {
            $identifier = (string) ($folder['identifier'] ?? '');
            $children = self::markActiveAndExpanded(
                array_values($folder['children'] ?? []),
                $activeIdentifier,
            );
            $active = self::isSameFolder($identifier, $activeIdentifier);
            $childExpanded = false;
            foreach ($children as $child) {
                if ($child['active'] || $child['expanded']) {
                    $childExpanded = true;
                    break;
                }
            }
            $marked[] = array_merge($folder, [
                'active' => $active,
                'expanded' => $active || $childExpanded || self::isAncestorOrSelf($identifier, $activeIdentifier),
                'children' => $children,
            ]);
        }

        return $marked;
    }

    /**
     * @return list<array{identifier: string, label: string, totalFiles: int, openCount: int, active: bool, expanded: bool, children: list}>
     */
    private function buildChildren(int $storageUid, Folder $folder, string $activeIdentifier): array
    {
        $nodes = [];
        foreach ($folder->getSubfolders() as $subfolder) {
            if (in_array($subfolder->getName(), self::IGNORE_DIRECTORIES, true)) {
                continue;
            }
            $identifier = $subfolder->getIdentifier();
            $counts = $this->countFilesInFolder($storageUid, $identifier, false);
            $children = $this->buildChildren($storageUid, $subfolder, $activeIdentifier);
            $active = self::isSameFolder($identifier, $activeIdentifier);
            $childExpanded = false;
            foreach ($children as $child) {
                if ($child['active'] || $child['expanded']) {
                    $childExpanded = true;
                    break;
                }
            }
            $nodes[] = [
                'identifier' => $identifier,
                'label' => rtrim($subfolder->getName(), '/') . '/',
                'totalFiles' => $counts['total'],
                'openCount' => $counts['open'],
                'active' => $active,
                'expanded' => $active || $childExpanded || self::isAncestorOrSelf($identifier, $activeIdentifier),
                'children' => $children,
            ];
        }

        usort($nodes, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $nodes;
    }

    private function firstBrowsableFolderIdentifier(Folder $rootFolder): ?string
    {
        $candidates = [];
        foreach ($rootFolder->getSubfolders() as $subfolder) {
            if (in_array($subfolder->getName(), self::IGNORE_DIRECTORIES, true)) {
                continue;
            }
            $candidates[] = $subfolder;
        }
        usort(
            $candidates,
            static fn(Folder $a, Folder $b): int => strcmp($a->getName(), $b->getName()),
        );

        return $candidates[0]?->getIdentifier();
    }

    /**
     * @return array{identifier: string, label: string, totalFiles: int, openCount: int, active: bool, expanded: bool, children: list}
     */
    private function emptyNode(string $identifier, string $label, bool $active, bool $expanded): array
    {
        return [
            'identifier' => $identifier,
            'label' => $label,
            'totalFiles' => 0,
            'openCount' => 0,
            'active' => $active,
            'expanded' => $expanded,
            'children' => [],
        ];
    }

    private static function normalizeIdentifierStatic(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '/') {
            return '/';
        }

        return '/' . trim($value, '/') . '/';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
