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

use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\FileUploadService;
use NITSAN\NsT3AF\Mcp\Service\McpPublicUrlService;
use NITSAN\NsT3AF\Service\PublicUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * @internal
 */
final class FileUploadServiceAssertFileNameTest extends TestCase
{
    private FileUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FileUploadService(
            $this->createMock(ConnectionPool::class),
            $this->createMock(StorageRepository::class),
            $this->createMock(AdvancedSettingsService::class),
            new PublicUrlValidator(),
            $this->createMock(McpPublicUrlService::class),
        );
    }

    #[Test]
    #[DataProvider('deniedFileNamesProvider')]
    public function assertFileNameIsAllowedRejectsDangerousNames(string $fileName): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->assertFileNameIsAllowed($fileName);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function deniedFileNamesProvider(): iterable
    {
        yield 'php' => ['shell.php'];
        yield 'phtml' => ['drop.phtml'];
        yield 'html' => ['page.html'];
        yield 'htm' => ['page.htm'];
        yield 'htaccess' => ['.htaccess'];
        yield 'double extension' => ['image.php.jpg'];
    }

    #[Test]
    #[DataProvider('allowedFileNamesProvider')]
    public function assertFileNameIsAllowedAcceptsSafeNames(string $fileName): void
    {
        $this->expectNotToPerformAssertions();
        $this->service->assertFileNameIsAllowed($fileName);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allowedFileNamesProvider(): iterable
    {
        yield 'jpg' => ['photo.jpg'];
        yield 'png' => ['banner.PNG'];
        yield 'svg' => ['icon.svg'];
    }
}
