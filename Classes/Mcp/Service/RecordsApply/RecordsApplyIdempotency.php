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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

use const JSON_THROW_ON_ERROR;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Makes a records_apply call safe to retry: the same requestId with the same payload never writes twice.
 *
 * One row per (backend user, tool, requestId) in tx_nst3af_mcp_idempotency:
 *  - `pending`: some call has taken the id and is running. It is inserted BEFORE the transaction starts, so a
 *    parallel retry sees it, and it is deleted again when the call fails (a failed call may be retried freely),
 *  - `done`: written INSIDE the engine transaction, just before the commit, together with the response. Either
 *    the data and the row exist, or neither does. A retry gets the stored response back, marked `replayed`.
 *
 * Reusing an id with a different payload is refused while the row is `done` or a fresh `pending`: it is almost
 * always a client bug, and silently running or replaying the wrong thing would be worse. A `pending` row that
 * nobody finishes (the process died) is taken over after PENDING_TIMEOUT seconds, including when the retry
 * sends a corrected payload (the stored hash is overwritten). A `done` row expires after the configured lifetime.
 *
 * Dry runs never take, store or replay an id.
 */
readonly class RecordsApplyIdempotency
{
    public const TABLE = 'tx_nst3af_mcp_idempotency';

    /** A pending row older than this is a call that died; a retry may take it over. */
    public const PENDING_TIMEOUT = 600;

    public const MAX_REQUEST_ID_LENGTH = 128;

    private const STATE_PENDING = 'pending';

    private const STATE_DONE = 'done';

    public function __construct(
        private ConnectionPool $connectionPool,
        private AdvancedSettingsService $settings,
    ) {}

    /**
     * @throws ToolCallException when the id is not usable
     */
    public static function assertValidRequestId(string $requestId): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,' . self::MAX_REQUEST_ID_LENGTH . '}$/D', $requestId) !== 1) {
            throw new ToolCallException(
                sprintf(
                    'requestId must be 1 to %d characters of letters, digits and . _ : - (for example a UUID). Nothing was written.',
                    self::MAX_REQUEST_ID_LENGTH,
                ),
                1790500014,
            );
        }
    }

    /**
     * What makes two calls "the same request": everything that changes what is written.
     *
     * Field-name maps inside each record (and bulk ``set`` maps) are sorted so a retry that only reorders
     * keys still matches. Table order, record order and list order are left alone — those decide creation
     * order, NEW resolution and sorting.
     *
     * @param array<mixed> $datamap
     * @param array<mixed> $cmdmap
     * @param array<mixed> $bulk
     */
    public static function payloadHash(array $datamap, array $cmdmap, array $bulk, bool $strict, bool $append): string
    {
        return hash('sha256', json_encode([
            self::canonicalizeRecordMaps($datamap),
            self::canonicalizeRecordMaps($cmdmap),
            self::canonicalizeBulk($bulk),
            $strict,
            $append,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Keep table and record order; sort only each record's field-name map.
     *
     * @param array<mixed> $tables
     * @return array<mixed>
     */
    private static function canonicalizeRecordMaps(array $tables): array
    {
        $out = [];
        foreach ($tables as $table => $records) {
            if (!is_array($records)) {
                $out[$table] = $records;
                continue;
            }
            $canonRecords = [];
            foreach ($records as $id => $fields) {
                $canonRecords[$id] = is_array($fields) ? self::sortFieldMap($fields) : $fields;
            }
            $out[$table] = $canonRecords;
        }

        return $out;
    }

    /**
     * Keep list order; sort only each entry's ``set`` field-name map.
     *
     * @param array<mixed> $bulk
     * @return list<mixed>
     */
    private static function canonicalizeBulk(array $bulk): array
    {
        $out = [];
        foreach ($bulk as $entry) {
            if (!is_array($entry)) {
                $out[] = $entry;
                continue;
            }
            $canon = [];
            foreach ($entry as $key => $value) {
                $canon[$key] = $key === 'set' && is_array($value) ? self::sortFieldMap($value) : $value;
            }
            $out[] = $canon;
        }

        return $out;
    }

    /**
     * @param array<mixed> $fields
     * @return array<mixed>
     */
    private static function sortFieldMap(array $fields): array
    {
        ksort($fields);

        return $fields;
    }

    /**
     * Read-only first look, before anything is validated: a retry of a finished call must come back with its
     * stored answer even if the request would not pass validation any more (the records it deleted are gone).
     *
     * @return RecordsApplyResult|null the stored answer of a finished call, or null when the call has to run
     * @throws ToolCallException when the id was used with another payload, or a call with it is running
     */
    public function lookup(string $tool, string $requestId, string $payloadHash): ?RecordsApplyResult
    {
        $userUid = $this->userUid();
        $this->purgeExpired($userUid, $tool, $requestId);

        $row = $this->fetch($userUid, $tool, $requestId);
        if ($row === null) {
            return null;
        }

        return $this->resolveExisting($row, $payloadHash);
    }

    /**
     * Takes the id for this call, right before its transaction starts.
     *
     * @return RecordsApplyIdempotencyLock|RecordsApplyResult the lock, or the stored answer when a parallel call finished first
     * @throws ToolCallException when the id was used with another payload, or a call with it is running
     */
    public function acquire(string $tool, string $requestId, string $payloadHash): RecordsApplyIdempotencyLock|RecordsApplyResult
    {
        $userUid = $this->userUid();
        $connection = $this->connection();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = bin2hex(random_bytes(8));
            $now = time();

            try {
                $connection->insert(self::TABLE, [
                    'be_user_uid' => $userUid,
                    'tool' => $tool,
                    'request_id' => $requestId,
                    'payload_hash' => $payloadHash,
                    'state' => self::STATE_PENDING,
                    'lock_token' => $token,
                    'response' => '',
                    'crdate' => $now,
                    'tstamp' => $now,
                ]);

                return new RecordsApplyIdempotencyLock($userUid, $tool, $requestId, $token);
            } catch (UniqueConstraintViolationException) {
                $row = $this->fetch($userUid, $tool, $requestId);
                if ($row === null) {
                    // Released between our insert and our read: try once more.
                    continue;
                }

                $replay = $this->resolveExisting($row, $payloadHash);
                if ($replay !== null) {
                    return $replay;
                }

                // A pending row nobody finished: take it over, but only if it is still the stale one.
                // Overwrite payload_hash so a corrected retry (different hash) owns the row from here on.
                $affected = $connection->update(
                    self::TABLE,
                    ['lock_token' => $token, 'tstamp' => $now, 'payload_hash' => $payloadHash],
                    ['uid' => (int) $row['uid'], 'state' => self::STATE_PENDING, 'lock_token' => (string) $row['lock_token']],
                );
                if ($affected === 1) {
                    return new RecordsApplyIdempotencyLock($userUid, $tool, $requestId, $token);
                }

                throw self::inProgress();
            }
        }

        throw self::inProgress();
    }

    /**
     * Stores the answer. Call it INSIDE the engine transaction, just before the commit.
     *
     * @param array<string, mixed> $response
     * @throws \RuntimeException when this call no longer owns the row
     */
    public function complete(RecordsApplyIdempotencyLock $lock, array $response): void
    {
        $affected = $this->connection()->update(
            self::TABLE,
            [
                'state' => self::STATE_DONE,
                'response' => json_encode($response, JSON_THROW_ON_ERROR),
                'tstamp' => time(),
            ],
            [
                'be_user_uid' => $lock->userUid,
                'tool' => $lock->tool,
                'request_id' => $lock->requestId,
                'lock_token' => $lock->token,
                'state' => self::STATE_PENDING,
            ],
        );

        if ($affected !== 1) {
            throw new \RuntimeException('The requestId lock was lost before the call finished.', 1790500016);
        }
    }

    /** The call failed and wrote nothing: free the id so the client can retry. */
    public function release(RecordsApplyIdempotencyLock $lock): void
    {
        $this->connection()->delete(self::TABLE, [
            'be_user_uid' => $lock->userUid,
            'tool' => $lock->tool,
            'request_id' => $lock->requestId,
            'lock_token' => $lock->token,
            'state' => self::STATE_PENDING,
        ]);
    }

    /**
     * Housekeeping for the cleanup command: finished rows past their lifetime, and pending rows of dead calls.
     *
     * @return int number of rows deleted
     */
    public function deleteExpired(): int
    {
        $now = time();
        $queryBuilder = $this->connection()->createQueryBuilder();
        $expr = $queryBuilder->expr();

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $expr->or(
                    $expr->and(
                        $expr->eq('state', $queryBuilder->createNamedParameter(self::STATE_DONE)),
                        $expr->lt('crdate', $queryBuilder->createNamedParameter($now - $this->settings->idempotencyTtlSeconds(), Connection::PARAM_INT)),
                    ),
                    $expr->and(
                        $expr->eq('state', $queryBuilder->createNamedParameter(self::STATE_PENDING)),
                        $expr->lt('tstamp', $queryBuilder->createNamedParameter($now - self::PENDING_TIMEOUT, Connection::PARAM_INT)),
                    ),
                ),
            )
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     * @return RecordsApplyResult|null the stored answer; null for a stale pending row the caller may take over
     * @throws ToolCallException
     */
    private function resolveExisting(array $row, string $payloadHash): ?RecordsApplyResult
    {
        $state = (string) $row['state'];
        $hashMatches = hash_equals((string) $row['payload_hash'], $payloadHash);

        if ($state === self::STATE_PENDING) {
            $stale = (int) $row['tstamp'] <= time() - self::PENDING_TIMEOUT;
            if ($stale) {
                // Dead call: reclaim with any payload (including a corrected one).
                return null;
            }
            if (!$hashMatches) {
                throw self::payloadMismatch();
            }

            throw self::inProgress();
        }

        if (!$hashMatches) {
            throw self::payloadMismatch();
        }

        if ($state === self::STATE_DONE) {
            $stored = json_decode((string) $row['response'], true);

            return RecordsApplyResult::fromStored(is_array($stored) ? $stored : [])->withReplayed();
        }

        throw self::inProgress();
    }

    private static function payloadMismatch(): ToolCallException
    {
        return new ToolCallException(
            'This requestId was already used with a different request. Use a new requestId for a different request, or resend the original one unchanged. Nothing was written.',
            1790500012,
        );
    }

    private static function inProgress(): ToolCallException
    {
        return new ToolCallException(
            'A call with this requestId is still running. Nothing was written by this call. Wait and retry with the same requestId.',
            1790500013,
        );
    }

    private function purgeExpired(int $userUid, string $tool, string $requestId): void
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user_uid', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tool', $queryBuilder->createNamedParameter($tool)),
                $queryBuilder->expr()->eq('request_id', $queryBuilder->createNamedParameter($requestId)),
                $queryBuilder->expr()->eq('state', $queryBuilder->createNamedParameter(self::STATE_DONE)),
                $queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter(time() - $this->settings->idempotencyTtlSeconds(), Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(int $userUid, string $tool, string $requestId): ?array
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $row = $queryBuilder
            ->select('uid', 'payload_hash', 'state', 'lock_token', 'response', 'tstamp')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user_uid', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tool', $queryBuilder->createNamedParameter($tool)),
                $queryBuilder->expr()->eq('request_id', $queryBuilder->createNamedParameter($requestId)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function userUid(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $uid = $backendUser instanceof BackendUserAuthentication ? (int) ($backendUser->user['uid'] ?? 0) : 0;
        if ($uid <= 0) {
            throw new ToolCallException('requestId needs an authenticated backend user. Nothing was written.', 1790500015);
        }

        return $uid;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
    }
}
