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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use Doctrine\DBAL\Result;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\FileUploadService;
use NITSAN\NsT3AF\Mcp\Service\McpPublicUrlService;
use NITSAN\NsT3AF\Service\PublicUrlValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * @internal
 */
final class FileUploadServiceConsumeTokenTest extends TestCase
{
    #[Test]
    public function emptyTokenReturnsNull(): void
    {
        $service = new FileUploadService(
            $this->createMock(ConnectionPool::class),
            $this->createMock(StorageRepository::class),
            $this->createMock(AdvancedSettingsService::class),
            new PublicUrlValidator(),
            $this->createMock(McpPublicUrlService::class),
        );

        self::assertNull($service->consumeUploadToken(''));
    }

    #[Test]
    public function expiredOrUsedTokenReturnsNull(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'used' => 0,
            'expires' => time() - 10,
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('select')->willReturn($result);
        $connection->expects(self::never())->method('executeStatement');

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $service = new FileUploadService(
            $pool,
            $this->createMock(StorageRepository::class),
            $this->createMock(AdvancedSettingsService::class),
            new PublicUrlValidator(),
            $this->createMock(McpPublicUrlService::class),
        );

        self::assertNull($service->consumeUploadToken('plaintext-token'));
    }

    #[Test]
    public function validTokenIsConsumedOnce(): void
    {
        $row = [
            'uid' => 7,
            'used' => 0,
            'expires' => time() + 600,
            'be_user_uid' => 1,
            'storage_uid' => 1,
            'target_folder' => '/',
            'file_name' => 'photo.jpg',
        ];

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $connection = $this->createMock(Connection::class);
        $connection->method('select')->willReturn($result);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturn(1);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $service = new FileUploadService(
            $pool,
            $this->createMock(StorageRepository::class),
            $this->createMock(AdvancedSettingsService::class),
            new PublicUrlValidator(),
            $this->createMock(McpPublicUrlService::class),
        );

        $consumed = $service->consumeUploadToken('plaintext-token');

        self::assertIsArray($consumed);
        self::assertSame(7, (int) $consumed['uid']);
        self::assertSame('photo.jpg', $consumed['file_name']);
    }
}
