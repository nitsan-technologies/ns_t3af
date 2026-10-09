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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\File;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\FileService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\File\FileUploadTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileUploadToolBase64LimitTest extends TestCase
{
    #[Test]
    public function largeBase64ContentThrowsToolCallException(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('uploadFile');

        $advancedSettings = $this->createMock(AdvancedSettingsService::class);
        $advancedSettings->method('maxBase64UploadBytes')->willReturn(16);

        $tool = new FileUploadTool($fileService, new McpConfirmationPlanBuilder(), $advancedSettings);

        $payload = base64_encode(str_repeat('A', 64));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/file_upload_prepare/');

        $tool->execute('tiny.bin', $payload);
    }

    #[Test]
    public function smallBase64ContentIsAccepted(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService
            ->expects(self::once())
            ->method('uploadFile')
            ->with(1, '/', 'ok.bin', 'ABCD')
            ->willReturn([
                'uid' => 9,
                'name' => 'ok.bin',
                'identifier' => '/ok.bin',
                'size' => 4,
                'mimeType' => 'application/octet-stream',
            ]);

        $advancedSettings = $this->createMock(AdvancedSettingsService::class);
        $advancedSettings->method('maxBase64UploadBytes')->willReturn(16);

        $tool = new FileUploadTool($fileService, new McpConfirmationPlanBuilder(), $advancedSettings);

        $result = json_decode($tool->execute('ok.bin', base64_encode('ABCD')), true);

        self::assertIsArray($result);
        self::assertSame(9, $result['uid']);
    }

    #[Test]
    public function expectedSha1MismatchThrows(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('uploadFile');

        $advancedSettings = $this->createMock(AdvancedSettingsService::class);
        $advancedSettings->method('maxBase64UploadBytes')->willReturn(1024);

        $tool = new FileUploadTool($fileService, new McpConfirmationPlanBuilder(), $advancedSettings);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/expectedSha1/');

        $tool->execute('ok.bin', base64_encode('ABCD'), '', '/', 1, 'deadbeef', 0);
    }

    #[Test]
    public function expectedSizeMismatchThrows(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('uploadFile');

        $advancedSettings = $this->createMock(AdvancedSettingsService::class);
        $advancedSettings->method('maxBase64UploadBytes')->willReturn(1024);

        $tool = new FileUploadTool($fileService, new McpConfirmationPlanBuilder(), $advancedSettings);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/expectedSize/');

        $tool->execute('ok.bin', base64_encode('ABCD'), '', '/', 1, '', 99);
    }

    #[Test]
    public function contentPathExpectedSha1MismatchThrows(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('uploadFile');

        $tool = new FileUploadTool(
            $fileService,
            new McpConfirmationPlanBuilder(),
            $this->createMock(AdvancedSettingsService::class),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/expectedSha1/');

        $tool->execute('diagram.svg', '', '<svg></svg>', '/', 1, 'deadbeef', 0);
    }

    #[Test]
    public function contentPathExpectedSizeMismatchThrows(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::never())->method('uploadFile');

        $tool = new FileUploadTool(
            $fileService,
            new McpConfirmationPlanBuilder(),
            $this->createMock(AdvancedSettingsService::class),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches('/expectedSize/');

        $tool->execute('diagram.svg', '', '<svg></svg>', '/', 1, '', 1);
    }

    #[Test]
    public function rewrittenStoredFileIsAnnotatedWhenIntegrityWasRequested(): void
    {
        $inbound = '<svg></svg>';
        $fileService = $this->createMock(FileService::class);
        $fileService
            ->expects(self::once())
            ->method('uploadFile')
            ->with(1, '/', 'diagram.svg', $inbound)
            ->willReturn([
                'uid' => 72,
                'name' => 'diagram.svg',
                'identifier' => '/diagram.svg',
                'size' => 3370,
                'sha1' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'mimeType' => 'image/svg+xml',
            ]);

        $tool = new FileUploadTool(
            $fileService,
            new McpConfirmationPlanBuilder(),
            $this->createMock(AdvancedSettingsService::class),
        );

        $result = json_decode(
            $tool->execute('diagram.svg', '', $inbound, '/', 1, sha1($inbound), strlen($inbound)),
            true,
        );

        self::assertIsArray($result);
        self::assertTrue($result['rewritten']);
        self::assertTrue($result['integrityCheckedInbound']);
        self::assertSame(sha1($inbound), $result['inboundSha1']);
        self::assertSame(strlen($inbound), $result['inboundSize']);
        self::assertStringContainsString('rewrote', $result['note']);
    }
}
