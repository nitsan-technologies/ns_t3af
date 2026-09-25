<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class FileService
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ConnectionPool $connectionPool,
        private FileUploadService $fileUploadService,
        private AdvancedSettingsService $advancedSettingsService,
        private McpPublicUrlService $mcpPublicUrlService,
    ) {}

    /**
     * @return array{
     *     files: list<array{uid: int, name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int, publicUrl: string|null, sha1: string}>,
     *     directories: list<array{name: string, identifier: string, modificationTime: int}>,
     *     totalFiles: int,
     *     totalDirectories: int
     * }
     */
    public function listDirectory(int $storageUid, string $directoryPath, int $limit, int $offset): array
    {
        $limit = min(max($limit, 1), 500);
        $offset = max(0, $offset);

        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryPath);

        $totalFiles = $storage->countFilesInFolder($folder);
        $totalDirectories = $storage->countFoldersInFolder($folder);

        $files = [];
        foreach ($storage->getFilesInFolder($folder, $offset, $limit) as $file) {
            $files[] = $this->mapFileToArray($file);
        }

        $directories = [];
        foreach ($storage->getFoldersInFolder($folder, $offset, $limit) as $subfolder) {
            $directories[] = $this->mapFolderToArray($subfolder);
        }

        return [
            'files' => $files,
            'directories' => $directories,
            'totalFiles' => $totalFiles,
            'totalDirectories' => $totalDirectories,
        ];
    }

    /**
     * @return array{
     *     uid: int,
     *     name: string,
     *     identifier: string,
     *     size: int,
     *     mimeType: string,
     *     extension: string,
     *     modificationTime: int,
     *     publicUrl: string|null,
     *     sha1: string
     * }
     */
    public function getFileInfo(int $storageUid, string $fileIdentifier): array
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002001);
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
            'publicUrl' => $this->mcpPublicUrlService->makeAbsoluteUrl($file->getPublicUrl()),
            'sha1' => $file->getSha1(),
        ];
    }

    /**
     * Resolve sys_file uid from storage + identifier; 0 when missing or inaccessible.
     */
    public function resolveFileUid(int $storageUid, string $identifier): int
    {
        if ($storageUid <= 0 || trim($identifier) === '') {
            return 0;
        }

        try {
            $info = $this->getFileInfo($storageUid, $identifier);

            return (int) ($info['uid'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadFile(int $storageUid, string $directoryPath, string $fileName, string $content): array
    {
        $folder = $this->fileUploadService->resolveFolder($storageUid, $directoryPath);

        $tempFile = tempnam(sys_get_temp_dir(), 'nst3af_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file', 1712002003);
        }

        try {
            if (file_put_contents($tempFile, $content) === false) {
                throw new \RuntimeException('Failed to write temporary upload file', 1712002019);
            }
            $stored = $this->fileUploadService->storeFile($tempFile, $fileName, $folder);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return $this->enrichStoredFileResult($stored, $fileName);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadFileFromUrl(int $storageUid, string $directoryPath, string $url, string $fileName = ''): array
    {
        $folder = $this->fileUploadService->resolveFolder($storageUid, $directoryPath);

        $onlineMedia = $this->fileUploadService->tryCreateOnlineMedia($url, $folder);
        if ($onlineMedia instanceof File) {
            $data = $this->fileUploadService->describeFile($onlineMedia);
            $data['onlineMedia'] = true;

            return $data;
        }

        [$tempPath, $resolvedFileName] = $this->fileUploadService->downloadFromUrl($url, $fileName);
        try {
            $stored = $this->fileUploadService->storeFile($tempPath, $resolvedFileName, $folder);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return $this->enrichStoredFileResult($stored, $resolvedFileName);
    }

    /**
     * @return array{
     *     files: list<array{uid: int, name: string, identifier: string, size: int, mimeType: string, extension: string, storage: int, publicUrl: string|null}>,
     *     total: int
     * }
     */
    public function searchFiles(int $storageUid, string $namePattern, string $extension, int $limit, int $offset): array
    {
        $limit = min(max($limit, 1), 500);
        $offset = max(0, $offset);

        // Enforce BE file mounts before querying (same gate as list/get/upload).
        $this->getStorage($storageUid);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $countQueryBuilder->getRestrictions()->removeAll();

        $queryBuilder->select('uid', 'name', 'identifier', 'size', 'mime_type', 'extension', 'storage')->from('sys_file');
        $countQueryBuilder->count('uid')->from('sys_file');

        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER)),
        );
        $countQueryBuilder->andWhere(
            $countQueryBuilder->expr()->eq('storage', $countQueryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER)),
        );

        if ($namePattern !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like('name', $queryBuilder->createNamedParameter('%' . $namePattern . '%')),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->like('name', $countQueryBuilder->createNamedParameter('%' . $namePattern . '%')),
            );
        }

        if ($extension !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('extension', $queryBuilder->createNamedParameter($extension)),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('extension', $countQueryBuilder->createNamedParameter($extension)),
            );
        }

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        /** @var list<array{uid: int, name: string, identifier: string, size: int, mime_type: string, extension: string, storage: int}> $rows */
        $rows = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $publicUrlService = $this->mcpPublicUrlService;
        $files = array_map(static function (array $row) use ($resourceFactory, $publicUrlService): array {
            $uid = (int) $row['uid'];
            $publicUrl = null;
            try {
                $file = $resourceFactory->getFileObject($uid, $row);
                $publicUrl = $publicUrlService->makeAbsoluteUrl($file->getPublicUrl());
            } catch (\Throwable) {
                // Leave publicUrl null when the FAL object cannot be resolved.
            }

            return [
                'uid' => $uid,
                'name' => $row['name'],
                'identifier' => $row['identifier'],
                'size' => (int) $row['size'],
                'mimeType' => $row['mime_type'],
                'extension' => $row['extension'],
                'storage' => (int) $row['storage'],
                'publicUrl' => $publicUrl,
            ];
        }, $rows);

        return [
            'files' => $files,
            'total' => (int) $totalResult,
        ];
    }

    /** @return array{name: string, identifier: string} */
    public function createDirectory(int $storageUid, string $parentPath, string $directoryName): array
    {
        $storage = $this->getStorage($storageUid);
        $parentFolder = $storage->getFolder($parentPath);
        $folder = $storage->createFolder($directoryName, $parentFolder);

        return [
            'name' => $folder->getName(),
            'identifier' => $folder->getIdentifier(),
        ];
    }

    public function copyFile(int $storageUid, string $fileIdentifier, string $targetDirectoryPath): void
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002007);
        }

        $targetFolder = $storage->getFolder($targetDirectoryPath);
        $storage->copyFile($file, $targetFolder);
    }

    public function moveFile(int $storageUid, string $fileIdentifier, string $targetDirectoryPath): void
    {
        $this->assertDestructiveAllowed();
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002005);
        }

        $targetFolder = $storage->getFolder($targetDirectoryPath);
        $storage->moveFile($file, $targetFolder);
    }

    public function renameFile(int $storageUid, string $fileIdentifier, string $newName): void
    {
        $this->assertDestructiveAllowed();
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002006);
        }

        $storage->renameFile($file, $newName);
    }

    public function deleteFile(int $storageUid, string $fileIdentifier): void
    {
        $this->assertDestructiveAllowed();
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002004);
        }

        $storage->deleteFile($file);
    }

    public function moveDirectory(int $storageUid, string $directoryIdentifier, string $targetDirectoryPath): void
    {
        $this->assertDestructiveAllowed();
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $targetFolder = $storage->getFolder($targetDirectoryPath);
        $storage->moveFolder($folder, $targetFolder);
    }

    public function renameDirectory(int $storageUid, string $directoryIdentifier, string $newName): void
    {
        $this->assertDestructiveAllowed();
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $storage->renameFolder($folder, $newName);
    }

    public function deleteDirectory(int $storageUid, string $directoryIdentifier, bool $recursive): void
    {
        $this->assertDestructiveAllowed();
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $storage->deleteFolder($folder, $recursive);
    }

    /**
     * Resolve a FAL storage the acting backend user is allowed to use (S-02).
     *
     * Uses {@see BackendUserAuthentication::getFileStorages()} so non-admins are
     * limited to their file mounts (with StoragePermissionAspect applied). Admins
     * receive every storage via the same API.
     */
    private function getStorage(int $storageUid): ResourceStorage
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1712002017);
        }

        $accessible = $backendUser->getFileStorages();
        $storage = $accessible[$storageUid] ?? null;
        if ($storage instanceof ResourceStorage) {
            return $storage;
        }

        if ($this->storageRepository->findByUid($storageUid) === null) {
            throw new \RuntimeException('Storage not found: ' . $storageUid, 1712002000);
        }

        throw new \RuntimeException('Access denied to storage: ' . $storageUid, 1712002018);
    }

    private function assertDestructiveAllowed(): void
    {
        if (!$this->advancedSettingsService->allowDestructiveFileOps()) {
            throw new \RuntimeException(
                'Destructive file operations are disabled (mcpAllowDestructiveFileOps).',
                1712002020,
            );
        }
    }

    /**
     * @param array{file: File, deduplicated: bool} $stored
     * @return array<string, mixed>
     */
    private function enrichStoredFileResult(array $stored, string $requestedFileName): array
    {
        $data = $this->fileUploadService->describeFile($stored['file']);
        if ($stored['deduplicated']) {
            $data['deduplicated'] = true;
            $data['note'] = 'A file with identical content already existed in this storage (see identifier, '
                . 'possibly in a different folder than requested); it is returned instead of creating a duplicate.';
        } elseif ($requestedFileName !== '' && $stored['file']->getName() !== basename($requestedFileName)) {
            $data['renamedFrom'] = basename($requestedFileName);
            $data['note'] = 'A different file with this name already existed, so the upload was stored under a new name.';
        }

        return $data;
    }

    /**
     * @return array{uid: int, name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int, publicUrl: string|null, sha1: string}
     */
    private function mapFileToArray(File $file): array
    {
        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
            'publicUrl' => $this->mcpPublicUrlService->makeAbsoluteUrl($file->getPublicUrl()),
            'sha1' => $file->getSha1(),
        ];
    }

    /** @return array{name: string, identifier: string, modificationTime: int} */
    private function mapFolderToArray(Folder $folder): array
    {
        return [
            'name' => $folder->getName(),
            'identifier' => $folder->getIdentifier(),
            'modificationTime' => $folder->getModificationTime(),
        ];
    }
}
