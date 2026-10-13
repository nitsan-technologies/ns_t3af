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
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FileUploadServiceFontMagicTest extends UnitTestCase
{
    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    #[Test]
    public function randomBytesNamedWoff2AreRefused(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'woff');
        self::assertNotFalse($this->tempFile);
        file_put_contents($this->tempFile, random_bytes(2048));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1747320030);
        FileUploadService::assertFontFileIsGenuine($this->tempFile, 'woff2');
    }

    #[Test]
    public function polyglotWoff2HeaderPlusPhpIsRefused(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'woff');
        self::assertNotFalse($this->tempFile);
        // 48-byte stub: magic + zeros; length field claims 48, then PHP is appended → length mismatch.
        $header = 'wOF2' . str_repeat("\0", 4) . pack('N', 48) . str_repeat("\0", 36);
        file_put_contents($this->tempFile, $header . '<?php phpinfo(); ?>');

        $this->expectException(\InvalidArgumentException::class);
        FileUploadService::assertFontFileIsGenuine($this->tempFile, 'woff2');
    }

    #[Test]
    public function genuineWoff2HeaderWithMatchingLengthPasses(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'woff');
        self::assertNotFalse($this->tempFile);
        $body = str_repeat('A', 100);
        $size = 12 + strlen($body);
        // Minimal 12-byte check uses magic + length; pad to size with zeros for the rest of the header area.
        $file = 'wOF2' . str_repeat("\0", 4) . pack('N', $size) . $body;
        // Need at least 44 bytes for the size gate.
        if (strlen($file) < 44) {
            $file .= str_repeat("\0", 44 - strlen($file));
            $size = strlen($file);
            $file = 'wOF2' . str_repeat("\0", 4) . pack('N', $size) . substr($file, 12);
        }
        file_put_contents($this->tempFile, $file);

        FileUploadService::assertFontFileIsGenuine($this->tempFile, 'woff2');
        self::assertFileExists($this->tempFile);
    }
}
