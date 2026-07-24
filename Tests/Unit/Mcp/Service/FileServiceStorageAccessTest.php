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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Service\PublicUrlValidator;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class FileServiceStorageAccessTest extends TestCase
{
    /** @var mixed */
    private mixed $previousBeUser = null;

    protected function setUp(): void
    {
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousBeUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBeUser;
        }
    }

    public function testGetFileInfoAllowsStoragePresentInUserFileStorages(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $file = $this->createMock(\TYPO3\CMS\Core\Resource\File::class);
        $file->method('getUid')->willReturn(10);
        $file->method('getName')->willReturn('doc.pdf');
        $file->method('getIdentifier')->willReturn('/doc.pdf');
        $file->method('getSize')->willReturn(100);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getExtension')->willReturn('pdf');
        $file->method('getModificationTime')->willReturn(1);
        $file->method('getPublicUrl')->willReturn(null);
        $storage->method('getFileByIdentifier')->with('/doc.pdf')->willReturn($file);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([1 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new FileService(
            $this->createMock(StorageRepository::class),
            $this->createMock(ConnectionPool::class),
            new PublicUrlValidator(),
        );

        $info = $service->getFileInfo(1, '/doc.pdf');

        self::assertSame('doc.pdf', $info['name']);
        self::assertSame(10, $info['uid']);
    }

    /**
     * S-02: a non-admin MCP token cannot reach storages outside its file mounts.
     */
    public function testGetFileInfoDeniesStorageOutsideUserFileMounts(): void
    {
        $otherStorage = $this->createMock(ResourceStorage::class);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([1 => $otherStorage]);
        $GLOBALS['BE_USER'] = $backendUser;

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(99)->willReturn(
            $this->createMock(ResourceStorage::class),
        );

        $service = new FileService(
            $storageRepository,
            $this->createMock(ConnectionPool::class),
            new PublicUrlValidator(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Access denied to storage: 99');
        $service->getFileInfo(99, '/secret.pdf');
    }

    public function testGetFileInfoRequiresAuthenticatedBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $service = new FileService(
            $this->createMock(StorageRepository::class),
            $this->createMock(ConnectionPool::class),
            new PublicUrlValidator(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated backend user available');
        $service->getFileInfo(1, '/doc.pdf');
    }
}
