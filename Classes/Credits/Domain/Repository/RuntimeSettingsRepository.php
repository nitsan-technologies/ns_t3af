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

namespace NITSAN\NsT3AF\Credits\Domain\Repository;

use NITSAN\NsT3AF\Credits\CreditsConstants;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
class RuntimeSettingsRepository
{
    private const TABLE = 'tx_nst3af_runtime_setting';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findSingleton(): ?array
    {
        $row = $this->connection()->select(
            ['*'],
            self::TABLE,
            ['uid' => CreditsConstants::RUNTIME_SETTING_UID],
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    public function updateSingleton(array $fields): void
    {
        $this->connection()->update(
            self::TABLE,
            $fields,
            ['uid' => CreditsConstants::RUNTIME_SETTING_UID],
        );
    }

    public function insertSingleton(): void
    {
        $this->connection()->insert(
            self::TABLE,
            [
                'uid' => CreditsConstants::RUNTIME_SETTING_UID,
                'credit_mode' => 0,
                'selected_license_ext_key' => 'ns_t3af',
                'license_keys' => '',
                't3planet_api_base_url' => '',
            ],
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
