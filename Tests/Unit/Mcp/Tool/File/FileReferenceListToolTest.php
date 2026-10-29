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

use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\File\FileReferenceListTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class FileReferenceListToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tt_content']['ctrl']['type'] = 'CType';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']);
        parent::tearDown();
    }

    #[Test]
    public function textmediaImageFieldListsAssetsInstead(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('recordExists')->with('tt_content', 82)->willReturn(true);
        $recordService->method('findByUid')->with('tt_content', 82, ['uid', 'CType'])->willReturn([
            'uid' => 82,
            'CType' => 'textmedia',
        ]);
        $recordService->expects(self::once())
            ->method('findFileReferences')
            ->with('tt_content', 82, 'assets')
            ->willReturn([['uid' => 501, 'uid_local' => 12]]);

        $tca = $this->createMock(TcaSchemaService::class);
        $tca->method('getFileFields')->with('tt_content')->willReturn(['image', 'assets']);
        $tca->method('getShownFields')->with('tt_content', 'textmedia')->willReturn(['assets', 'bodytext', 'header']);

        $json = (new FileReferenceListTool($recordService, $tca))->execute('tt_content', 82, 'image');
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('assets', $payload['fieldName']);
        self::assertSame(1, $payload['total']);
        self::assertStringContainsString("'assets' instead", $payload['note']);
    }

    #[Test]
    public function unknownFileFieldReturnsError(): void
    {
        $tca = $this->createMock(TcaSchemaService::class);
        $tca->method('getFileFields')->willReturn(['assets']);

        $json = (new FileReferenceListTool($this->createMock(RecordService::class), $tca))
            ->execute('tt_content', 10, 'not_a_field');
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $payload);
        self::assertSame(['assets'], $payload['availableFileFields']);
    }
}
