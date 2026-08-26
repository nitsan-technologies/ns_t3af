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

namespace NITSAN\NsT3AF\AiLabel\Event;

/**
 * Lets extensions add or drop tables that carry AI Label fields.
 */
final class CollectApplicableTablesEvent
{
    /**
     * @param list<string> $tables
     */
    public function __construct(private array $tables) {}

    public function addTable(string $table): void
    {
        $table = trim($table);
        if ($table === '' || in_array($table, $this->tables, true)) {
            return;
        }
        $this->tables[] = $table;
    }

    public function removeTable(string $table): void
    {
        $this->tables = array_values(array_filter(
            $this->tables,
            static fn(string $existing): bool => $existing !== $table,
        ));
    }

    /**
     * @return list<string>
     */
    public function getTables(): array
    {
        return $this->tables;
    }
}
