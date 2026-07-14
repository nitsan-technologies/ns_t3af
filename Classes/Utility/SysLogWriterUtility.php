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

namespace NITSAN\NsT3AF\Utility;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes to sys_log across TYPO3 v12–v14 schema differences.
 *
 * v12–v13: details_nr + details
 * v14+: message + data (+ component)
 */
final class SysLogWriterUtility
{
    private const TABLE = 'sys_log';

    /**
     * @param array<string, mixed> $extraData
     */
    public static function insert(
        string $logMessage,
        string $logLevel,
        string $channel,
        array $extraData = [],
        ?BackendUserAuthentication $backendUser = null,
    ): void {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        if (!$connection->isConnected()) {
            return;
        }

        $backendUser ??= $GLOBALS['BE_USER'] ?? null;

        $userId = 0;
        $workspace = 0;
        $data = $extraData;

        if ($backendUser instanceof BackendUserAuthentication) {
            if (isset($backendUser->user['uid'])) {
                $userId = (int) $backendUser->user['uid'];
            }
            $workspace = (int) $backendUser->workspace;
            if ($backUserId = $backendUser->getOriginalUserIdWhenInSwitchUserMode()) {
                $data['originalUser'] = $backUserId;
            }
        }

        $encodedData = $data === [] ? '' : (string) json_encode($data);
        $escapedMessage = str_replace('%', '%%', $logMessage);
        $ip = GeneralUtility::getIndpEnv('REMOTE_ADDR') ?: '';
        $tstamp = time();
        $channel = mb_substr($channel, 0, 20);

        $connection->insert(
            self::TABLE,
            self::usesLegacySchema($connection)
                ? [
                    'userid' => $userId,
                    'type' => 1,
                    'channel' => $channel,
                    'action' => 0,
                    'error' => 1,
                    'level' => $logLevel,
                    'details_nr' => 0,
                    'details' => $escapedMessage,
                    'log_data' => $encodedData,
                    'IP' => $ip,
                    'tstamp' => $tstamp,
                    'workspace' => $workspace,
                ]
                : [
                    'userid' => $userId,
                    'type' => 1,
                    'channel' => $channel,
                    'action' => 0,
                    'error' => $logLevel === 'error' ? 1 : 0,
                    'level' => $logLevel,
                    'message' => $logMessage,
                    'details' => $escapedMessage,
                    'data' => $encodedData,
                    'log_data' => $encodedData,
                    'component' => $channel,
                    'IP' => $ip,
                    'tstamp' => $tstamp,
                    'workspace' => $workspace,
                    'event_pid' => -1,
                    'request_id' => '',
                    'time_micro' => microtime(true),
                ],
        );
    }

    public static function usesLegacySchema(?Connection $connection = null): bool
    {
        $connection ??= GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $columns = $connection->createSchemaManager()->listTableColumns(self::TABLE);

        return isset($columns['details_nr']);
    }

    /**
     * @return list<string>
     */
    public static function getReadableColumns(?Connection $connection = null): array
    {
        $connection ??= GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $available = array_keys($connection->createSchemaManager()->listTableColumns(self::TABLE));
        $wanted = [
            'uid', 'userid', 'type', 'channel', 'action', 'error', 'level',
            'details_nr', 'details', 'message', 'data', 'log_data', 'IP', 'tstamp', 'workspace', 'component',
        ];

        return array_values(array_intersect($wanted, $available));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeLogRow(array $row): array
    {
        if (empty($row['details']) && !empty($row['message'])) {
            $row['details'] = $row['message'];
        }

        return $row;
    }
}
