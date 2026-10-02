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
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * @internal
 */
final class FileUploadServiceSsrfTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function privateUrlProvider(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/secret.jpg'];
        yield 'rfc1918' => ['http://10.0.0.5/file.png'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'cgnat' => ['http://100.64.0.1/x.bin'];
    }

    #[Test]
    #[DataProvider('privateUrlProvider')]
    public function downloadFromUrlRejectsPrivateHostsBeforeHttp(string $url): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        $advanced = $this->createMock(AdvancedSettingsService::class);
        $advanced->method('maxFileSizeMb')->willReturn(10);

        $service = new FileUploadService(
            $this->createMock(ConnectionPool::class),
            $this->createMock(StorageRepository::class),
            $advanced,
            new PublicUrlValidator(),
            $this->createMock(McpPublicUrlService::class),
            $requestFactory,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/private|reserved|http/i');

        $service->downloadFromUrl($url);
    }
}
