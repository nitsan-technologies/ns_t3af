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

use NITSAN\NsT3AF\AiLabel\Event\CollectApplicableTablesEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Resolves tables that store AI Label fields (defaults, module settings, then event).
 */
final class ApplicableTablesResolver
{
    public const DEFAULT_TABLES = ['tt_content', 'pages', 'sys_file_metadata'];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AiLabelSettingsService $settingsService,
    ) {}

    /**
     * @return list<string>
     */
    public function getTables(): array
    {
        $configured = $this->settingsService->getConfiguredApplicableTables();
        $tables = $configured !== []
            ? $configured
            : self::DEFAULT_TABLES;

        $event = new CollectApplicableTablesEvent($tables);
        $this->eventDispatcher->dispatch($event);

        $resolved = [];
        foreach ($event->getTables() as $table) {
            if (!preg_match('/^[a-z0-9_]+$/', $table) || in_array($table, $resolved, true)) {
                continue;
            }
            $resolved[] = $table;
        }

        return $resolved;
    }

    public function isApplicable(string $table): bool
    {
        return in_array($table, $this->getTables(), true);
    }
}
