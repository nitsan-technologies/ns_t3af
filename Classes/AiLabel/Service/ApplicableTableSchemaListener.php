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
 * R1.2 configurable applicable tables with plausibility check before DDL.
 */
final class ApplicableTableSchemaListener
{
    public function __construct(
        private readonly ApplicableTablesResolver $applicableTablesResolver,
    ) {}

    private const COLUMN_DDL = <<<'SQL'
 `%s` VARCHAR(32) NOT NULL DEFAULT 'not_reviewed',
 `%s` VARCHAR(16) NOT NULL DEFAULT 'automatic',
 `%s` VARCHAR(64) NOT NULL DEFAULT '',
 `%s` VARCHAR(128) NOT NULL DEFAULT '',
 `%s` VARCHAR(128) NOT NULL DEFAULT '',
 `%s` TEXT,
 `%s` INT(11) UNSIGNED NOT NULL DEFAULT 0,
 `%s` INT(11) UNSIGNED NOT NULL DEFAULT 0,
 `%s` INT(11) UNSIGNED NOT NULL DEFAULT 0,
 `%s` VARCHAR(64) NOT NULL DEFAULT '',
 `%s` VARCHAR(64) NOT NULL DEFAULT '',
 `%s` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
 `%s` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
 `%s` VARCHAR(255) NOT NULL DEFAULT '',
 `%s` VARCHAR(64) NOT NULL DEFAULT ''
SQL;

    public function appendStatements(AlterTableDefinitionStatementsEvent $event): void
    {
        foreach ($this->applicableTablesResolver->getTables() as $table) {
            if (in_array($table, ApplicableTablesResolver::DEFAULT_TABLES, true)) {
                continue;
            }

            $columns = $this->columnNames();
            $ddl = sprintf(self::COLUMN_DDL, ...$columns);
            $event->addSqlData('ALTER TABLE `' . $table . '` ADD ' . str_replace("\n", ', ADD ', trim($ddl)));
        }
    }

    /**
     * @return list<string>
     */
    private function columnNames(): array
    {
        return [
            'tx_nst3af_ailabel_involvement',
            'tx_nst3af_ailabel_labelling_mode',
            'tx_nst3af_ailabel_exemption_reason',
            'tx_nst3af_ailabel_ai_system',
            'tx_nst3af_ailabel_ai_vendor',
            'tx_nst3af_ailabel_internal_note',
            'tx_nst3af_ailabel_confirmed_by',
            'tx_nst3af_ailabel_confirmed_at',
            'tx_nst3af_ailabel_exported_at',
            'tx_nst3af_ailabel_version_hash',
            'tx_nst3af_ailabel_recording_source',
            'tx_nst3af_ailabel_public_interest',
            'tx_nst3af_ailabel_human_review',
            'tx_nst3af_ailabel_responsible_person',
            'tx_nst3af_ailabel_generation_group',
        ];
    }
}
