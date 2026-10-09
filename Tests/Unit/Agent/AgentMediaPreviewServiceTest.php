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

        self::assertSame([['uid' => 42, 'url' => '', 'name' => 'image.png']], $references);
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

        self::assertSame([['uid' => 0, 'url' => 'fileadmin/x.webp', 'name' => '']], $references);
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
