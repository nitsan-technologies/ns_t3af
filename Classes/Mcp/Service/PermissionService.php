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

namespace NITSAN\NsT3AF\Mcp\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

readonly class PermissionService
{
    /** @return array{table: string, canSelect: bool, canModify: bool} */
    public function checkTableAccess(string $table): array
    {
        $backendUser = $this->getBackendUser();

        return [
            'table' => $table,
            'canSelect' => $backendUser->check('tables_select', $table),
            'canModify' => $backendUser->check('tables_modify', $table),
        ];
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
