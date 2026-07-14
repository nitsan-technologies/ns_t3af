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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Service;

use Doctrine\DBAL\ParameterType;

use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;
use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_SCHEME;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

readonly class FileService
{
    private const MAX_DOWNLOAD_BYTES = 104857600;

    public function __construct(
        private StorageRepository $storageRepository,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{
     *     files: list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int}>,
     *     directories: list<array{name: string, identifier: string, modificationTime: int}>,
     *     totalFiles: int,
     *     totalDirectories: int
     * }
     */
    public function listDirectory(int $storageUid, string $directoryPath, int $limit, int $offset): array
    {
        $limit = min(max($limit, 1), 500);

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
     *     publicUrl: string|null
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
            'publicUrl' => $file->getPublicUrl(),
        ];
    }

    /**
     * @return array{uid: int, name: string, identifier: string, size: int, mimeType: string}
     */
    public function uploadFile(int $storageUid, string $directoryPath, string $fileName, string $content): array
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryPath);

        $tempFile = tempnam(sys_get_temp_dir(), 'nst3af_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file', 1712002003);
        }

        try {
            file_put_contents($tempFile, $content);
            $file = $storage->addFile($tempFile, $folder, $fileName);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    /**
     * @return array{uid: int, name: string, identifier: string, size: int, mimeType: string}
     */
    public function uploadFileFromUrl(int $storageUid, string $directoryPath, string $url, string $fileName = ''): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http and https URLs are allowed', 1712002010);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Invalid URL: missing host', 1712002014);
        }

        $resolvedIp = gethostbyname($host);
        if (
            $resolvedIp === $host
            || filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new \RuntimeException('URL resolves to a private or reserved IP address', 1712002015);
        }

        if ($fileName === '') {
            $path = parse_url($url, PHP_URL_PATH);
            $fileName = is_string($path) ? basename($path) : '';
            if ($fileName === '' || $fileName === '.') {
                $fileName = 'download_' . bin2hex(random_bytes(4));
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'max_redirects' => 5,
                'follow_location' => 1,
                'method' => 'GET',
                'user_agent' => 'TYPO3-AI-Universe-MCP/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'nst3af_url_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file', 1712002003);
        }

        try {
            $stream = @fopen($url, 'r', false, $context);
            if ($stream === false) {
                throw new \RuntimeException('Failed to download file from URL', 1712002011);
            }

            $bytesWritten = 0;
            $fp = fopen($tempFile, 'w');
            if ($fp === false) {
                fclose($stream);

                throw new \RuntimeException('Failed to open temporary file for writing', 1712002016);
            }

            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    break;
                }
                $bytesWritten += strlen($chunk);
                if ($bytesWritten > self::MAX_DOWNLOAD_BYTES) {
                    fclose($fp);
                    fclose($stream);

                    throw new \RuntimeException('Downloaded file exceeds maximum allowed size of 100 MB', 1712002012);
                }
                fwrite($fp, $chunk);
            }

            fclose($fp);
            fclose($stream);

            $storage = $this->getStorage($storageUid);
            $folder = $storage->getFolder($directoryPath);
            $file = $storage->addFile($tempFile, $folder, $fileName);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    /**
     * @return array{
     *     files: list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, storage: int}>,
     *     total: int
     * }
     */
    public function searchFiles(int $storageUid, string $namePattern, string $extension, int $limit, int $offset): array
    {
        $limit = min(max($limit, 1), 500);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $countQueryBuilder->getRestrictions()->removeAll();

        $queryBuilder->select('name', 'identifier', 'size', 'mime_type', 'extension', 'storage')->from('sys_file');
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

        /** @var list<array{name: string, identifier: string, size: int, mime_type: string, extension: string, storage: int}> $rows */
        $rows = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $files = array_map(static fn(array $row): array => [
            'name' => $row['name'],
            'identifier' => $row['identifier'],
            'size' => (int) $row['size'],
            'mimeType' => $row['mime_type'],
            'extension' => $row['extension'],
            'storage' => (int) $row['storage'],
        ], $rows);

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
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002006);
        }

        $storage->renameFile($file, $newName);
    }

    public function deleteFile(int $storageUid, string $fileIdentifier): void
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002004);
        }

        $storage->deleteFile($file);
    }

    public function moveDirectory(int $storageUid, string $directoryIdentifier, string $targetDirectoryPath): void
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $targetFolder = $storage->getFolder($targetDirectoryPath);
        $storage->moveFolder($folder, $targetFolder);
    }

    public function renameDirectory(int $storageUid, string $directoryIdentifier, string $newName): void
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $storage->renameFolder($folder, $newName);
    }

    public function deleteDirectory(int $storageUid, string $directoryIdentifier, bool $recursive): void
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $storage->deleteFolder($folder, $recursive);
    }

    private function getStorage(int $storageUid): ResourceStorage
    {
        $storage = $this->storageRepository->findByUid($storageUid);

        if ($storage === null) {
            throw new \RuntimeException('Storage not found: ' . $storageUid, 1712002000);
        }

        return $storage;
    }

    /** @return array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int} */
    private function mapFileToArray(File $file): array
    {
        return [
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
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
