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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\BrandContextDocumentExtractor;
use PHPUnit\Framework\TestCase;

final class BrandContextDocumentExtractorTest extends TestCase
{
    private BrandContextDocumentExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new BrandContextDocumentExtractor();
    }

    public function testExtractPlainTextAndMarkdown(): void
    {
        $txt = tempnam(sys_get_temp_dir(), 'aiu-txt-');
        self::assertNotFalse($txt);
        file_put_contents($txt, "Brand voice guide\nLine two");

        $md = tempnam(sys_get_temp_dir(), 'aiu-md-');
        self::assertNotFalse($md);
        file_put_contents($md, "# Heading\nParagraph");

        $result = $this->extractor->extractFromFiles([
            ['path' => $txt, 'name' => 'guide.txt'],
            ['path' => $md, 'name' => 'style.md'],
        ]);

        @unlink($txt);
        @unlink($md);

        self::assertStringContainsString('Brand voice guide', $result['extract']);
        self::assertStringContainsString('# Heading', $result['extract']);
        self::assertCount(2, $result['files']);
        self::assertSame([], $result['warnings']);
    }

    public function testSkipsUnsupportedFileType(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aiu-rtf-');
        self::assertNotFalse($file);
        $rtfPath = $file . '.rtf';
        rename($file, $rtfPath);
        file_put_contents($rtfPath, 'fake rtf');

        $result = $this->extractor->extractFromFiles([
            ['path' => $rtfPath, 'name' => 'notes.rtf'],
        ]);

        @unlink($rtfPath);

        self::assertSame('', $result['extract']);
        self::assertNotEmpty($result['warnings']);
    }
}
