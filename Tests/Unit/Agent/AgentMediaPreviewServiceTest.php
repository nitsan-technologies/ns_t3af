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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentMediaPreviewService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Finding the images a tool result is about.
 *
 * @internal
 */
final class AgentMediaPreviewServiceTest extends TestCase
{
    #[Test]
    public function aGeneratedImageIsFoundByItsFileUid(): void
    {
        $references = AgentMediaPreviewService::fileReferences(['fileUid' => 42, 'publicUrl' => '/fileadmin/ai/image.png', 'name' => 'image.png']);

        self::assertSame([['uid' => 42, 'url' => '', 'name' => 'image.png', 'named' => false]], $references);
    }

    #[Test]
    public function theFilesToAttachAreFoundInArgumentsAndResult(): void
    {
        $fromArguments = AgentMediaPreviewService::fileReferences(['table' => 'tt_content', 'uid' => 255, 'fieldName' => 'assets', 'fileUids' => '145, 146,x']);
        $fromResult = AgentMediaPreviewService::fileReferences(['table' => 'tt_content', 'uid' => 255, 'fileUids' => [145], 'referenceUids' => [99]]);

        self::assertSame([145, 146], array_column($fromArguments, 'uid'));
        self::assertSame([true, true], array_column($fromArguments, 'named'));
        self::assertSame([145], array_column($fromResult, 'uid'));
    }

    #[Test]
    public function anAttachedFileWithoutThumbnailKeepsItsName(): void
    {
        $image = $this->file('lake.jpg', 'image/jpeg', '/fileadmin/lake.jpg');
        $pdf = $this->file('price-list.pdf', 'application/pdf', '/fileadmin/price-list.pdf');
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->willReturnMap([[145, [], $image], [146, [], $pdf]]);
        $service = new AgentMediaPreviewService($resourceFactory, $this->createMock(ConnectionPool::class));

        $previews = $service->forDetails(['fileUids' => '145,146']);

        self::assertSame(['lake.jpg', 'price-list.pdf'], array_column($previews, 'name'));
        self::assertSame('/fileadmin/lake.jpg', $previews[0]['href']);
        self::assertSame('', $previews[1]['url']);
        self::assertSame([], $service->forDetails(['files' => [['uid' => 146, 'mimeType' => 'application/pdf']]]));
    }

    private function file(string $name, string $mimeType, string $publicUrl): File
    {
        $file = $this->createMock(File::class);
        $file->method('checkActionPermission')->willReturn(true);
        $file->method('isMissing')->willReturn(false);
        $file->method('getMimeType')->willReturn($mimeType);
        $file->method('getName')->willReturn($name);
        $file->method('getPublicUrl')->willReturn($publicUrl);
        $file->method('process')->willThrowException(new \RuntimeException('No image processing in unit tests'));

        return $file;
    }

    #[Test]
    public function fileListRowsAreFoundButPagesAreNot(): void
    {
        $references = AgentMediaPreviewService::fileReferences([
            'files' => [
                ['uid' => 5, 'name' => 'a.jpg', 'identifier' => '/a.jpg', 'mimeType' => 'image/jpeg'],
                ['uid' => 6, 'name' => 'b.pdf', 'identifier' => '/b.pdf', 'extension' => 'pdf'],
                ['uid' => 5, 'name' => 'a.jpg', 'mimeType' => 'image/jpeg'],
            ],
            'page' => ['uid' => 12, 'title' => 'Home'],
        ]);

        self::assertSame([5, 6], array_column($references, 'uid'));
    }

    #[Test]
    public function aPlainImageUrlIsKept(): void
    {
        $references = AgentMediaPreviewService::fileReferences(['result' => ['publicUrl' => 'fileadmin/x.webp']]);

        self::assertSame([['uid' => 0, 'url' => 'fileadmin/x.webp', 'name' => '', 'named' => false]], $references);
    }

    #[Test]
    public function theLimitIsRespected(): void
    {
        $files = [];
        for ($i = 1; $i <= 20; ++$i) {
            $files[] = ['uid' => $i, 'mimeType' => 'image/png'];
        }

        self::assertCount(3, AgentMediaPreviewService::fileReferences(['files' => $files], 3));
    }

    #[Test]
    public function onlySafeUrlsAreUsed(): void
    {
        self::assertSame('/fileadmin/a.png', AgentMediaPreviewService::normalizeUrl('fileadmin/a.png'));
        self::assertSame('https://cdn.example.org/a.png', AgentMediaPreviewService::normalizeUrl('https://cdn.example.org/a.png'));
        self::assertSame('', AgentMediaPreviewService::normalizeUrl('javascript:alert(1)'));
        self::assertSame('', AgentMediaPreviewService::normalizeUrl('//evil.example/a.png'));
        self::assertSame('', AgentMediaPreviewService::normalizeUrl('data:image/png;base64,AAAA'));
    }
}
