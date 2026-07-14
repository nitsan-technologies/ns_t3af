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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Prompt;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Unified storage for custom AI prompt rows (all extensions).
 */
final class AiPromptRepository
{
    public const TABLE = 'tx_nst3af_ai_prompt';

    public const KIND_GLOBAL = 'global';

    public const KIND_SIDEBAR = 'sidebar';

    public const KIND_RTE = 'rte';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function isTableRegistered(): bool
    {
        $tca = $GLOBALS['TCA'] ?? [];

        return is_array($tca) && isset($tca[self::TABLE]);
    }

    /**
     * @return list<array{
     *   uid: int,
     *   prompt_title: string,
     *   prompt_text: string,
     *   prompt_type: string,
     *   scope: string,
     *   is_default: int
     * }>
     */
    public function findGlobalRows(
        string $extensionKey,
        string $categoryId,
        int $storagePid = 0,
        bool $customOnly = false,
    ): array {
        if (!$this->isTableRegistered()) {
            return [];
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $effectivePid = max(0, $storagePid);
            $constraints = [
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                $qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId)),
                $qb->expr()->eq('prompt_kind', $qb->createNamedParameter(self::KIND_GLOBAL)),
                $this->pidConstraint($qb, $effectivePid),
            ];
            if ($customOnly) {
                $constraints[] = $qb->expr()->eq('is_default', $qb->createNamedParameter(0, Connection::PARAM_INT));
            }

            return $qb->select('uid', 'prompt_title', 'prompt_text', 'prompt_type', 'scope', 'is_default')
                ->from(self::TABLE)
                ->where(...$constraints)
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{uid: int, prompt_title: string, prompt_text: string}>
     */
    public function findSidebarRows(string $extensionKey): array
    {
        if (!$this->isTableRegistered()) {
            return [];
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();

            return $qb->select('uid', 'prompt_title', 'prompt_text')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                    $qb->expr()->eq('category_id', $qb->createNamedParameter('sidebar')),
                    $qb->expr()->eq('prompt_kind', $qb->createNamedParameter(self::KIND_SIDEBAR)),
                )
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{
     *   uid: int,
     *   prompt_title: string,
     *   prompt_text: string,
     *   prompt_type: string,
     *   scope: string,
     *   is_default: int
     * }>
     */
    public function findRteRows(
        string $extensionKey,
        int $storagePid = 0,
        bool $customOnly = false,
    ): array {
        if (!$this->isTableRegistered()) {
            return [];
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $effectivePid = max(0, $storagePid);
            $constraints = [
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                $qb->expr()->eq('category_id', $qb->createNamedParameter('rte')),
                $qb->expr()->eq('prompt_kind', $qb->createNamedParameter(self::KIND_RTE)),
                $this->pidConstraint($qb, $effectivePid),
            ];
            if ($customOnly) {
                $constraints[] = $qb->expr()->eq('is_default', $qb->createNamedParameter(0, Connection::PARAM_INT));
            }

            return $qb->select('uid', 'prompt_title', 'prompt_text', 'prompt_type', 'scope', 'is_default')
                ->from(self::TABLE)
                ->where(...$constraints)
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
    }

    public function findRtePromptTextByTypeAndTitle(
        string $extensionKey,
        string $promptType,
        string $promptTitle,
        int $storagePid = 0,
    ): ?string {
        if (!$this->isTableRegistered()) {
            return null;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $effectivePid = max(0, $storagePid);
            $row = $qb->select('prompt_text')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                    $qb->expr()->eq('prompt_type', $qb->createNamedParameter($promptType)),
                    $qb->expr()->eq('prompt_title', $qb->createNamedParameter($promptTitle)),
                    $qb->expr()->eq('category_id', $qb->createNamedParameter('rte')),
                    $qb->expr()->eq('prompt_kind', $qb->createNamedParameter(self::KIND_RTE)),
                    $this->pidConstraint($qb, $effectivePid),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $text = trim((string) ($row['prompt_text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    public function findLatestRtePromptText(string $extensionKey, string $promptType, int $storagePid = 0): ?string
    {
        $rows = $this->findRteRows($extensionKey, $storagePid, true);
        $latestText = null;
        $latestUid = 0;

        foreach ($rows as $row) {
            if ((string) ($row['prompt_type'] ?? '') !== $promptType) {
                continue;
            }
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid >= $latestUid) {
                $text = trim((string) ($row['prompt_text'] ?? ''));
                if ($text !== '') {
                    $latestUid = $uid;
                    $latestText = $text;
                }
            }
        }

        return $latestText;
    }

    public function findPromptTextByTypeAndTitle(
        string $extensionKey,
        string $scope,
        string $promptType,
        string $promptTitle,
        int $storagePid = 0,
    ): ?string {
        if (!$this->isTableRegistered()) {
            return null;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $effectivePid = max(0, $storagePid);
            $row = $qb->select('prompt_text')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                    $qb->expr()->eq('scope', $qb->createNamedParameter($scope)),
                    $qb->expr()->eq('prompt_type', $qb->createNamedParameter($promptType)),
                    $qb->expr()->eq('prompt_title', $qb->createNamedParameter($promptTitle)),
                    $qb->expr()->eq('prompt_kind', $qb->createNamedParameter(self::KIND_GLOBAL)),
                    $this->pidConstraint($qb, $effectivePid),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $text = trim((string) ($row['prompt_text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function insert(array $values): bool
    {
        if (!$this->isTableRegistered()) {
            return false;
        }

        return $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $values) > 0;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function update(int $uid, array $values): bool
    {
        if (!$this->isTableRegistered() || $uid <= 0) {
            return false;
        }

        return $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, $values, ['uid' => $uid]) > 0;
    }

    public function softDelete(int $uid): bool
    {
        if (!$this->isTableRegistered() || $uid <= 0) {
            return false;
        }

        return $this->update($uid, [
            'deleted' => 1,
            'tstamp' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
        ]);
    }

    public function recordBelongsToStorage(int $uid, int $storagePid, string $extensionKey): bool
    {
        if ($storagePid <= 0) {
            return true;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $count = $qb->count('uid')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                    $qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchOne();

            return ((int) $count) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function globalPromptExists(string $extensionKey, string $scope, string $promptType, int $storagePid = 0): bool
    {
        if (!$this->isTableRegistered()) {
            return false;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $count = $qb->count('uid')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                    $qb->expr()->eq('scope', $qb->createNamedParameter($scope)),
                    $qb->expr()->eq('prompt_type', $qb->createNamedParameter($promptType)),
                    $qb->expr()->eq('prompt_kind', $qb->createNamedParameter(self::KIND_GLOBAL)),
                    $this->pidConstraint($qb, max(0, $storagePid)),
                )
                ->executeQuery()
                ->fetchOne();

            return ((int) $count) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function countRowsForCategory(string $extensionKey, string $categoryId): int
    {
        if (!$this->isTableRegistered()) {
            return 0;
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();

            return (int) $qb->count('uid')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                    $qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId)),
                )
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{uid: int, prompt_title: string, prompt_text: string, prompt_type: string, scope: string, is_default: int}>
     */
    public function findRowsByPromptType(string $extensionKey, string $promptType, int $storagePid = 0): array
    {
        if (!$this->isTableRegistered()) {
            return [];
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $effectivePid = max(0, $storagePid);

            return $qb->select('uid', 'prompt_title', 'prompt_text', 'prompt_type', 'scope', 'is_default')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->eq('extension_key', $qb->createNamedParameter($extensionKey)),
                    $qb->expr()->eq('prompt_type', $qb->createNamedParameter($promptType)),
                    $qb->expr()->eq('prompt_kind', $qb->createNamedParameter(self::KIND_GLOBAL)),
                    $this->pidConstraint($qb, $effectivePid),
                )
                ->orderBy('prompt_title', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Latest custom (non-default) prompt text for a prompt type at site storage.
     */
    public function findLatestCustomPromptText(string $extensionKey, string $promptType, int $storagePid = 0): ?string
    {
        $rows = $this->findRowsByPromptType($extensionKey, $promptType, $storagePid);
        $latestText = null;
        $latestUid = 0;

        foreach ($rows as $row) {
            if ((int) ($row['is_default'] ?? 0) !== 0) {
                continue;
            }
            $uid = (int) ($row['uid'] ?? 0);
            if ($uid >= $latestUid) {
                $text = trim((string) ($row['prompt_text'] ?? ''));
                if ($text !== '') {
                    $latestUid = $uid;
                    $latestText = $text;
                }
            }
        }

        return $latestText;
    }

    /**
     * @return \TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression|string
     */
    private function pidConstraint(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, int $storagePid): \TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression|string
    {
        return $qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, Connection::PARAM_INT));
    }
}
