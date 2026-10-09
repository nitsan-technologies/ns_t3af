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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * May the backend user read this file? Storage AND file mount.
 *
 * TYPO3 only switches on file mount checks for a regular backend request (StoragePermissionsAspect). The MCP
 * server runs without one (the local stdio server never has a request), so ResourceStorage would answer "yes"
 * for every file of a storage the user can see. This asks the user's file mounts directly, so an editor with a
 * mount on /user_upload/ cannot attach or run a payload file from /restricted/.
 */
readonly class RecordsApplyFileAccess
{
    public function __construct(private ResourceFactory $resourceFactory) {}

    public function canRead(int $fileUid): bool
    {
        if ($fileUid <= 0) {
            return false;
        }

        try {
            return $this->canReadFile($this->resourceFactory->getFileObject($fileUid));
        } catch (\Throwable) {
            // The same answer for "no such file" and "not yours", so file uids cannot be probed.
            return false;
        }
    }

    public function canReadFile(FileInterface $file): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        try {
            $storage = $file->getStorage();
            if (!isset($backendUser->getFileStorages()[$storage->getUid()]) || !$storage->checkFileActionPermission('read', $file)) {
                return false;
            }

            return self::isInsideAFileMount($backendUser->getFileMountRecords(), $storage->getUid(), $file->getIdentifier());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed> $mountRecords the user's file mounts; each has an "identifier" like "1:/user_upload/"
     */
    public static function isInsideAFileMount(array $mountRecords, int $storageUid, string $fileIdentifier): bool
    {
        foreach ($mountRecords as $mount) {
            $identifier = is_array($mount) ? (string) ($mount['identifier'] ?? '') : '';
            if (!str_contains($identifier, ':')) {
                continue;
            }

            [$mountStorage, $path] = explode(':', $identifier, 2);
            if ((int) $mountStorage !== $storageUid) {
                continue;
            }

            $path = rtrim($path, '/');
            if ($path === '' || str_starts_with($fileIdentifier, $path . '/')) {
                return true;
            }
        }

        return false;
    }
}
