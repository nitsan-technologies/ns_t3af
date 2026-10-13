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
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;

final class FileUploadServiceDedupeGuardTest extends TestCase
{
    #[Test]
    public function jpegMimeIsNotConsistentWithWoff2Extension(): void
    {
        self::assertFalse(FileUploadService::isExtensionConsistentWithMime('woff2', 'image/jpeg'));
    }

    #[Test]
    public function fontWoff2MimeIsConsistentWithWoff2Extension(): void
    {
        self::assertTrue(FileUploadService::isExtensionConsistentWithMime('woff2', 'font/woff2'));
    }

    #[Test]
    public function folderIdentifiersNormalizeTrailingSlash(): void
    {
        self::assertSame('/user_upload/', FileUploadService::normalizeFolderIdentifier('/user_upload'));
        self::assertSame('/user_upload/', FileUploadService::normalizeFolderIdentifier('/user_upload/'));
        self::assertSame('/', FileUploadService::normalizeFolderIdentifier('/'));
    }

    #[Test]
    public function dedupeRefusesExistingJpegWhenUploadNameIsWoff2(): void
    {
        $existing = $this->createMock(File::class);
        $existing->method('getExtension')->willReturn('jpg');
        $existing->method('getMimeType')->willReturn('image/jpeg');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/identical file already exists as "\.jpg"/');

        FileUploadService::assertDedupeNameMatchesExisting($existing, 'fake.woff2', 'woff2');
    }

    #[Test]
    public function isFileInFolderComparesParentIdentifiers(): void
    {
        $parent = $this->createMock(Folder::class);
        $parent->method('getIdentifier')->willReturn('/user_upload/');

        $file = $this->createMock(File::class);
        $file->method('getParentFolder')->willReturn($parent);

        $same = $this->createMock(Folder::class);
        $same->method('getIdentifier')->willReturn('/user_upload');

        $other = $this->createMock(Folder::class);
        $other->method('getIdentifier')->willReturn('/user_upload/qa/');

        self::assertTrue(FileUploadService::isFileInFolder($file, $same));
        self::assertFalse(FileUploadService::isFileInFolder($file, $other));
    }
}
