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

use NITSAN\NsT3AF\Mcp\Service\FileUploadService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileUploadServiceDownloadOptionsTest extends TestCase
{
    #[Test]
    public function pinnedDnsOptionsNeverCombineStreamWithCurl(): void
    {
        if (!defined('CURLOPT_RESOLVE')) {
            self::markTestSkipped('CURLOPT_RESOLVE is not available.');
        }

        $options = FileUploadService::buildDownloadRequestOptions(
            'https://cdn.example.com/photo.jpg',
            '203.0.113.10',
            false,
            10_485_760,
        );

        self::assertArrayHasKey('curl', $options);
        self::assertArrayNotHasKey('stream', $options);
        self::assertArrayHasKey('progress', $options);
    }

    #[Test]
    public function withoutDnsPinStreamIsUsedAndCurlIsAbsent(): void
    {
        $options = FileUploadService::buildDownloadRequestOptions(
            'https://cdn.example.com/photo.jpg',
            null,
            false,
            10_485_760,
        );

        self::assertTrue($options['stream'] ?? false);
        self::assertArrayNotHasKey('curl', $options);
    }

    #[Test]
    public function proxyDisablesDnsPinningAndUsesStream(): void
    {
        $options = FileUploadService::buildDownloadRequestOptions(
            'https://cdn.example.com/photo.jpg',
            '203.0.113.10',
            true,
            10_485_760,
        );

        self::assertTrue($options['stream'] ?? false);
        self::assertArrayNotHasKey('curl', $options);
    }
}
