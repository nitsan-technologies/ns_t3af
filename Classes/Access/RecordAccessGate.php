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

namespace NITSAN\NsT3AF\Access;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Checks be_groups tables_select / tables_modify for wizard record permissions.
 */
final class RecordAccessGate
{
    public function __construct(
        private readonly RecordPermissionCatalog $recordCatalog = new RecordPermissionCatalog(),
    ) {}

    public function canSelectTable(?BackendUserAuthentication $user, string $table): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return BackendPermissionCheck::isGranted($user, 'tables_select', $table);
    }

    public function canModifyTable(?BackendUserAuthentication $user, string $table): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return BackendPermissionCheck::isGranted($user, 'tables_modify', $table);
    }

    public function canSelectCatalogRow(?BackendUserAuthentication $user, string $catalogId): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $tables = $this->recordCatalog->tablesForCatalogId($catalogId);
        if ($tables === []) {
            return false;
        }

        foreach ($tables as $table) {
            if ($this->canSelectTable($user, $table)) {
                return true;
            }
        }

        return false;
    }

    public function canModifyCatalogRow(?BackendUserAuthentication $user, string $catalogId): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $tables = $this->recordCatalog->tablesForCatalogId($catalogId);
        if ($tables === []) {
            return false;
        }

        foreach ($tables as $table) {
            if ($this->canModifyTable($user, $table)) {
                return true;
            }
        }

        return false;
    }

    public function assertCanModifyTable(?BackendUserAuthentication $user, string $table): void
    {
        if ($this->canModifyTable($user, $table)) {
            return;
        }

        throw new RecordAccessDeniedException(table: $table);
    }

    public function assertCanModifyCatalogRow(?BackendUserAuthentication $user, string $catalogId): void
    {
        if ($this->canModifyCatalogRow($user, $catalogId)) {
            return;
        }

        throw new RecordAccessDeniedException(catalogId: $catalogId);
    }
}
