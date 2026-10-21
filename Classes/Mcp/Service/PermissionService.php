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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

readonly class PermissionService
{
    /**
     * Backend auth tables: never readable via generic MCP search/count for non-admins,
     * even if a group were misconfigured with tables_select.
     *
     * @var list<string>
     */
    private const ADMIN_ONLY_SELECT_TABLES = [
        'be_users',
        'be_groups',
        'be_sessions',
    ];

    /** @return array{table: string, canSelect: bool, canModify: bool} */
    public function checkTableAccess(string $table): array
    {
        $this->assertTableIsRegistered($table);
        $backendUser = $this->getBackendUser();

        return [
            'table' => $table,
            'canSelect' => $this->canSelectTable($table, $backendUser),
            'canModify' => $backendUser->check('tables_modify', $table),
        ];
    }

    /**
     * Refuse tables the current backend user may not list (MCP record_search / record_count / …).
     *
     * @throws \InvalidArgumentException code 1790600001
     */
    public function assertCanSelectTable(string $table): void
    {
        if ($this->canSelectTable($table, $this->getBackendUser())) {
            return;
        }

        throw new \InvalidArgumentException(
            sprintf('You are not allowed to read table "%s" with your backend account.', $table),
            1790600001,
        );
    }

    private function canSelectTable(string $table, BackendUserAuthentication $backendUser): bool
    {
        if (!$this->isRegisteredTable($table)) {
            return false;
        }

        if (in_array($table, self::ADMIN_ONLY_SELECT_TABLES, true) && !$backendUser->isAdmin()) {
            return false;
        }

        return $backendUser->check('tables_select', $table);
    }

    private function assertTableIsRegistered(string $table): void
    {
        if ($this->isRegisteredTable($table)) {
            return;
        }

        throw new \InvalidArgumentException(
            sprintf('Table "%s" is not registered in TYPO3.', $table),
            1790600002,
        );
    }

    private function isRegisteredTable(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $tca = $GLOBALS['TCA'] ?? null;

        return is_array($tca) && isset($tca[$table]) && is_array($tca[$table]);
    }

    /**
     * @param array<string, mixed> $pageRow
     * @return array{pageId: int, canShow: bool, canEdit: bool, canDelete: bool, canCreateSubpages: bool, canEditContent: bool, permissionBitmask: int}
     */
    public function checkPageAccess(array $pageRow): array
    {
        $backendUser = $this->getBackendUser();

        $perms = $backendUser->calcPerms($pageRow);
        $uid = $pageRow['uid'] ?? 0;

        return [
            'pageId' => is_int($uid) ? $uid : (int) (is_string($uid) ? $uid : 0),
            'canShow' => ($perms & Permission::PAGE_SHOW) === Permission::PAGE_SHOW,
            'canEdit' => ($perms & Permission::PAGE_EDIT) === Permission::PAGE_EDIT,
            'canDelete' => ($perms & Permission::PAGE_DELETE) === Permission::PAGE_DELETE,
            'canCreateSubpages' => ($perms & Permission::PAGE_NEW) === Permission::PAGE_NEW,
            'canEditContent' => ($perms & Permission::CONTENT_EDIT) === Permission::CONTENT_EDIT,
            'permissionBitmask' => $perms,
        ];
    }

    /** @return array{isAdmin: bool, tablesSelect: list<string>, tablesModify: list<string>, allowedLanguages: list<int>, filePermissions: array<string, bool>, webmounts: list<int>, filemounts: list<int>} */
    public function getPermissionSummary(): array
    {
        $backendUser = $this->getBackendUser();

        $groupData = $backendUser->groupData;

        /** @var array<string, bool> $filePermissions */
        $filePermissions = $backendUser->getFilePermissions();

        return [
            'isAdmin' => $backendUser->isAdmin(),
            'tablesSelect' => $this->parseCommaSeparatedList($this->getGroupDataString($groupData, 'tables_select')),
            'tablesModify' => $this->parseCommaSeparatedList($this->getGroupDataString($groupData, 'tables_modify')),
            'allowedLanguages' => $this->parseIntList($this->getGroupDataString($groupData, 'allowed_languages')),
            'filePermissions' => $filePermissions,
            'webmounts' => $this->parseIntList($this->getGroupDataString($groupData, 'webmounts')),
            'filemounts' => $this->parseIntList($this->getGroupDataString($groupData, 'filemounts')),
        ];
    }

    public function isAdmin(): bool
    {
        return $this->getBackendUser()->isAdmin();
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1714000010);
        }

        return $backendUser;
    }

    /** @param array<mixed> $groupData */
    private function getGroupDataString(array $groupData, string $key): string
    {
        $value = $groupData[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private function parseCommaSeparatedList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn(string $item): bool => $item !== '',
        ));
    }

    /** @return list<int> */
    private function parseIntList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_map(
            static fn(string $item): int => (int) $item,
            array_filter(
                array_map('trim', explode(',', $value)),
                static fn(string $item): bool => $item !== '',
            ),
        ));
    }
}
