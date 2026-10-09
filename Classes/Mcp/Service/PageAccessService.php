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

namespace NITSAN\NsT3AF\Mcp\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Page-level read gate for everything the agent / MCP read tools return.
 *
 * Table-level checks (tables_select) say nothing about *which* pages a backend
 * user may see. A non-admin editor must only read records on pages that are
 * inside their web mounts and grant "show" permission — exactly what the page
 * tree and list module enforce (BackendUtility::readPageAccess).
 */
readonly class PageAccessService
{
    public const ACCESS_DENIED_MESSAGE = "You don't have access to this page.";

    public function isUnrestricted(): bool
    {
        $user = $this->getBackendUser();

        return $user !== null && $user->isAdmin();
    }

    public function canReadPage(int $pageId): bool
    {
        $user = $this->getBackendUser();
        if ($user === null || $pageId < 0) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if ($pageId === 0) {
            return false;
        }

        return BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    /**
     * Column that ties a record of $table to the page it must be readable through:
     * a page is checked by its own uid, every other record by its pid.
     */
    public function anchorColumn(string $table): string
    {
        return $table === 'pages' ? 'uid' : 'pid';
    }

    /**
     * @param array<string, mixed> $row
     */
    public function canReadRecord(string $table, array $row): bool
    {
        if ($this->isUnrestricted()) {
            return true;
        }

        $column = $this->anchorColumn($table);
        $anchor = $row[$column] ?? null;
        if (!is_int($anchor) && !is_string($anchor)) {
            return false;
        }

        return $this->isAnchorAllowed($table, (int) $anchor);
    }

    /**
     * @param list<int> $anchorIds distinct values of anchorColumn($table)
     * @return list<int>
     */
    public function filterAllowedAnchors(string $table, array $anchorIds): array
    {
        if ($this->isUnrestricted()) {
            return $anchorIds;
        }

        return array_values(array_filter(
            $anchorIds,
            fn(int $id): bool => $this->isAnchorAllowed($table, $id),
        ));
    }

    private function isAnchorAllowed(string $table, int $anchor): bool
    {
        // Root-level records (pid 0) of non-page tables are not page scoped;
        // they stay governed by the table-level permission checks.
        if ($table !== 'pages' && $anchor === 0) {
            return true;
        }

        return $this->canReadPage($anchor);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
