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

namespace NITSAN\NsT3AF\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * AI Agent conversations: one row per conversation, owned by one backend user.
 *
 * A conversation has a home (module + page where it was started), a title, the provider
 * chosen for it and its messages as JSON. Every query is scoped by be_user_uid, so no
 * method returns or changes another user's conversation. Deleting is soft (deleted = 1);
 * {@see self::hardDeleteSoftDeletedBefore()} removes rows after a grace period.
 */
final class AgentConversationRepository
{
    public const TABLE = 'tx_nst3af_agent_conversation';

    public const SCOPE_PAGE = 'page';

    public const SCOPE_MODULE = 'module';

    public const SCOPE_USER = 'user';

    /** Columns for session lists (without the message payload). */
    private const SUMMARY_COLUMNS = [
        'uid', 'session_uuid', 'module_route', 'page_id', 'title', 'provider_identifier',
        'message_count', 'last_activity', 'crdate',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * The most recent conversation the configured scope opens automatically.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForScope(int $beUserUid, string $scope, string $moduleRoute, int $pageId): ?array
    {
        if ($beUserUid <= 0) {
            return null;
        }

        $qb = $this->queryBuilder();
        $qb->select('*')->from(self::TABLE)->where(...$this->ownerAndScope($qb, $beUserUid, $scope, $moduleRoute, $pageId));
        $row = $qb->orderBy('last_activity', 'DESC')->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySessionForUser(string $sessionUuid, int $beUserUid): ?array
    {
        if ($beUserUid <= 0 || !self::isValidUuid($sessionUuid)) {
            return null;
        }

        $qb = $this->queryBuilder();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('session_uuid', $qb->createNamedParameter($sessionUuid)),
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The user's conversations, newest activity first. With $currentScopeOnly, only those
     * of the given scope (same module + page for "page", same module for "module").
     *
     * @return list<array<string, mixed>> summary rows (no messages)
     */
    public function listForUser(
        int $beUserUid,
        bool $currentScopeOnly,
        string $scope,
        string $moduleRoute,
        int $pageId,
        int $limit = 20,
        int $offset = 0,
    ): array {
        if ($beUserUid <= 0) {
            return [];
        }

        $qb = $this->queryBuilder();
        $qb->select(...self::SUMMARY_COLUMNS)->from(self::TABLE);
        if ($currentScopeOnly) {
            $qb->where(...$this->ownerAndScope($qb, $beUserUid, $scope, $moduleRoute, $pageId));
        } else {
            $qb->where(...$this->ownerAndScope($qb, $beUserUid, self::SCOPE_USER, '', 0));
        }

        $rows = $qb->orderBy('last_activity', 'DESC')->addOrderBy('uid', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * @return array<string, mixed> the new row
     */
    public function create(int $beUserUid, string $moduleRoute, int $pageId, string $title = '', string $providerIdentifier = ''): array
    {
        $now = $this->now();
        $row = [
            'session_uuid' => self::newUuid(),
            'be_user_uid' => $beUserUid,
            'module_route' => mb_substr($moduleRoute, 0, 128),
            'page_id' => max(0, $pageId),
            'title' => mb_substr($title, 0, 255),
            'provider_identifier' => mb_substr($providerIdentifier, 0, 128),
            'message_count' => 0,
            'last_activity' => $now,
            'deleted' => 0,
            'messages' => '[]',
            'context' => '{}',
            'crdate' => $now,
            'tstamp' => $now,
        ];
        $this->connection()->insert(self::TABLE, $row);
        $row['uid'] = (int) $this->connection()->lastInsertId();

        return $row;
    }

    /**
     * Stores the messages of a conversation the user owns. Title and provider are only
     * written when given (the caller decides when they may change).
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $context
     */
    public function saveMessages(
        int $uid,
        int $beUserUid,
        array $messages,
        array $context,
        ?string $title = null,
        ?string $providerIdentifier = null,
    ): void {
        $now = $this->now();
        $values = [
            'messages' => json_encode(array_values($messages), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'context' => json_encode($context === [] ? new \stdClass() : $context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'message_count' => count($messages),
            'last_activity' => $now,
            'tstamp' => $now,
        ];
        if ($title !== null) {
            $values['title'] = mb_substr($title, 0, 255);
        }
        if ($providerIdentifier !== null) {
            $values['provider_identifier'] = mb_substr($providerIdentifier, 0, 128);
        }

        $this->connection()->update(self::TABLE, $values, ['uid' => $uid, 'be_user_uid' => $beUserUid, 'deleted' => 0]);
    }

    public function rename(string $sessionUuid, int $beUserUid, string $title): bool
    {
        if (!self::isValidUuid($sessionUuid) || $beUserUid <= 0) {
            return false;
        }

        return $this->connection()->update(
            self::TABLE,
            ['title' => mb_substr($title, 0, 255), 'tstamp' => $this->now()],
            ['session_uuid' => $sessionUuid, 'be_user_uid' => $beUserUid, 'deleted' => 0],
        ) > 0;
    }

    public function softDelete(string $sessionUuid, int $beUserUid): bool
    {
        if (!self::isValidUuid($sessionUuid) || $beUserUid <= 0) {
            return false;
        }

        return $this->connection()->update(
            self::TABLE,
            ['deleted' => 1, 'tstamp' => $this->now()],
            ['session_uuid' => $sessionUuid, 'be_user_uid' => $beUserUid, 'deleted' => 0],
        ) > 0;
    }

    /**
     * Soft-deletes the oldest conversations beyond the limits (0 = unlimited).
     *
     * @return int number of conversations soft-deleted
     */
    public function enforceLimits(int $beUserUid, string $scope, string $moduleRoute, int $pageId, int $maxPerScope, int $maxPerUser): int
    {
        $removed = 0;
        if ($maxPerScope > 0) {
            $removed += $this->softDeleteBeyond($beUserUid, $scope, $moduleRoute, $pageId, $maxPerScope);
        }
        if ($maxPerUser > 0) {
            $removed += $this->softDeleteBeyond($beUserUid, self::SCOPE_USER, '', 0, $maxPerUser);
        }

        return $removed;
    }

    public function softDeleteInactiveSince(int $cutoffTimestamp): int
    {
        if ($cutoffTimestamp <= 0) {
            return 0;
        }

        $qb = $this->queryBuilder();

        return $qb->update(self::TABLE)
            ->set('deleted', 1)
            ->set('tstamp', $this->now())
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->lt('last_activity', $qb->createNamedParameter($cutoffTimestamp, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    public function hardDeleteSoftDeletedBefore(int $cutoffTimestamp): int
    {
        if ($cutoffTimestamp <= 0) {
            return 0;
        }

        $qb = $this->queryBuilder();

        return $qb->delete(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(1, Connection::PARAM_INT)),
                $qb->expr()->lt('tstamp', $qb->createNamedParameter($cutoffTimestamp, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    public static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }

    private function softDeleteBeyond(int $beUserUid, string $scope, string $moduleRoute, int $pageId, int $keep): int
    {
        $qb = $this->queryBuilder();
        $uids = $qb->select('uid')
            ->from(self::TABLE)
            ->where(...$this->ownerAndScope($qb, $beUserUid, $scope, $moduleRoute, $pageId))
            ->orderBy('last_activity', 'DESC')->addOrderBy('uid', 'DESC')
            ->setFirstResult($keep)
            ->setMaxResults(1000)
            ->executeQuery()
            ->fetchFirstColumn();
        if ($uids === []) {
            return 0;
        }

        $update = $this->queryBuilder();

        return $update->update(self::TABLE)
            ->set('deleted', 1)
            ->set('tstamp', $this->now())
            ->where(
                $update->expr()->in('uid', $update->createNamedParameter(array_map('intval', $uids), Connection::PARAM_INT_ARRAY)),
                $update->expr()->eq('be_user_uid', $update->createNamedParameter($beUserUid, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * @return list<string>
     */
    private function ownerAndScope(QueryBuilder $qb, int $beUserUid, string $scope, string $moduleRoute, int $pageId): array
    {
        $constraints = [
            $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
            $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
        ];
        if ($scope === self::SCOPE_PAGE || $scope === self::SCOPE_MODULE) {
            $constraints[] = $qb->expr()->eq('module_route', $qb->createNamedParameter($moduleRoute));
        }
        if ($scope === self::SCOPE_PAGE) {
            $constraints[] = $qb->expr()->eq('page_id', $qb->createNamedParameter(max(0, $pageId), Connection::PARAM_INT));
        }

        return array_map('strval', $constraints);
    }

    private function queryBuilder(): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();

        return $qb;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }

    private function now(): int
    {
        return (int) ($GLOBALS['EXEC_TIME'] ?? time());
    }
}
