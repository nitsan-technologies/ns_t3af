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

namespace NITSAN\NsT3AF\Settings;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
class ExtensionSettingsRepository
{
    private const TABLE = 'tx_nst3af_extension_setting';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findByExtensionKey(string $extensionKey, int $storagePid = 0): ?array
    {
        $row = $this->connection()->select(
            ['*'],
            self::TABLE,
            [
                'extension_key' => $extensionKey,
                'pid' => $storagePid,
            ],
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByExtensionKey(string $extensionKey): array
    {
        $rows = $this->connection()->select(
            ['*'],
            self::TABLE,
            [
                'extension_key' => $extensionKey,
            ],
        )->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }

    public function insert(string $extensionKey, int $storagePid = 0): void
    {
        $now = time();
        $this->connection()->insert(
            self::TABLE,
            [
                'pid' => $storagePid,
                'extension_key' => $extensionKey,
                'settings_json' => '{}',
                'crdate' => $now,
                'tstamp' => $now,
            ],
        );
    }

    public function updateSettingsJson(string $extensionKey, string $settingsJson, int $storagePid = 0): void
    {
        $this->connection()->update(
            self::TABLE,
            [
                'settings_json' => $settingsJson,
                'tstamp' => time(),
            ],
            [
                'extension_key' => $extensionKey,
                'pid' => $storagePid,
            ],
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
