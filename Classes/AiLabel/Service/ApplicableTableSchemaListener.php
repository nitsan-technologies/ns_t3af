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

use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;

/**
 * Extra applicable tables: CREATE TABLE fragments TYPO3 merges like ext_tables.sql.
 */
final class ApplicableTableSchemaListener
{
    private const COLUMN_DDL = <<<'SQL'
    tx_nst3af_ailabel_involvement VARCHAR(32) NOT NULL DEFAULT 'not_reviewed',
    tx_nst3af_ailabel_labelling_mode VARCHAR(16) NOT NULL DEFAULT 'automatic',
    tx_nst3af_ailabel_exemption_reason VARCHAR(64) NOT NULL DEFAULT '',
    tx_nst3af_ailabel_ai_system VARCHAR(128) NOT NULL DEFAULT '',
    tx_nst3af_ailabel_ai_vendor VARCHAR(128) NOT NULL DEFAULT '',
    tx_nst3af_ailabel_internal_note TEXT,
    tx_nst3af_ailabel_confirmed_by INT(11) UNSIGNED NOT NULL DEFAULT 0,
    tx_nst3af_ailabel_confirmed_at INT(11) UNSIGNED NOT NULL DEFAULT 0,
    tx_nst3af_ailabel_exported_at INT(11) UNSIGNED NOT NULL DEFAULT 0,
    tx_nst3af_ailabel_version_hash VARCHAR(64) NOT NULL DEFAULT '',
    tx_nst3af_ailabel_recording_source VARCHAR(64) NOT NULL DEFAULT '',
    tx_nst3af_ailabel_public_interest TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    tx_nst3af_ailabel_human_review TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    tx_nst3af_ailabel_responsible_person VARCHAR(255) NOT NULL DEFAULT '',
    tx_nst3af_ailabel_generation_group VARCHAR(64) NOT NULL DEFAULT ''
SQL;

    public function __construct(
        private readonly ApplicableTablesResolver $applicableTablesResolver,
    ) {}

    public function appendStatements(AlterTableDefinitionStatementsEvent $event): void
    {
        foreach ($this->applicableTablesResolver->getTables() as $table) {
            if (in_array($table, ApplicableTablesResolver::DEFAULT_TABLES, true)) {
                continue;
            }

            $event->addSqlData(
                'CREATE TABLE `' . $table . '` (' . "\n" . self::COLUMN_DDL . "\n);",
            );
        }
    }
}
