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

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\File\FileReferenceAddTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * @internal
 */
final class FileReferenceAddToolTest extends TestCase
{
    private const TEXTMEDIA_SHOWITEM = '--palette--;;headers, bodytext, --div--;Media, assets, --palette--;;gallerySettings';

    private const IMAGE_SHOWITEM = '--palette--;;headers, --div--;Images, image, --palette--;;gallerySettings';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tt_content']['ctrl']['delete'] = 'deleted';
        $GLOBALS['TCA']['tt_content']['ctrl']['type'] = 'CType';
        $GLOBALS['TCA']['tt_content']['types'] = [
            'textmedia' => ['showitem' => self::TEXTMEDIA_SHOWITEM],
            'image' => ['showitem' => self::IMAGE_SHOWITEM],
        ];
        $GLOBALS['TCA']['tt_content']['palettes'] = [
            'headers' => ['showitem' => 'header, subheader'],
            'gallerySettings' => ['showitem' => 'imagecols'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row = ['uid' => 10, 'pid' => 1, 'deleted' => 0]): RecordService&MockObject
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')->willReturn($row);

        return $recordService;
    }

    /**
     * @param list<string> $fileFields
     */
    private function tca(array $fileFields = ['image', 'assets']): TcaSchemaService&MockObject
    {
        $tcaSchemaService = $this->getMockBuilder(TcaSchemaService::class)
            ->onlyMethods(['getFileFields'])
            ->getMock();
        $tcaSchemaService->method('getFileFields')->willReturn($fileFields);

        return $tcaSchemaService;
    }

    /**
     * @param array<int, array{name?: string, exists?: bool, missing?: bool, readable?: bool}> $files
     */
    private function files(array $files): ResourceFactory&MockObject
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->method('getFileObject')->willReturnCallback(
            function (int $uid) use ($files): File {
                if (!isset($files[$uid])) {
                    throw new FileDoesNotExistException('No file found for given UID: ' . $uid, 1317178604);
                }
                $file = $this->createMock(File::class);
                $file->method('getUid')->willReturn($uid);
                $file->method('getName')->willReturn($files[$uid]['name'] ?? 'photo-' . $uid . '.jpg');
                $file->method('exists')->willReturn($files[$uid]['exists'] ?? true);
                $file->method('isMissing')->willReturn($files[$uid]['missing'] ?? false);
                $file->method('checkActionPermission')->willReturn($files[$uid]['readable'] ?? true);

                return $file;
            },
        );

        return $resourceFactory;
    }

    private function tool(
        ?RecordService $recordService = null,
        ?ResourceFactory $resourceFactory = null,
        ?DataHandlerService $dataHandlerService = null,
        ?TcaSchemaService $tcaSchemaService = null,
    ): FileReferenceAddTool {
        return new FileReferenceAddTool(
            $dataHandlerService ?? $this->createMock(DataHandlerService::class),
            $tcaSchemaService ?? $this->tca(),
            new McpConfirmationPlanBuilder(),
            $recordService ?? $this->record(),
            $resourceFactory ?? $this->files([]),
        );
    }

    #[Test]
    public function executeRejectsInvalidFileUids(): void
    {
        $result = json_decode($this->tool()->execute('tt_content', 1, 'image', '0,abc'), true);

        self::assertIsArray($result);
        self::assertSame('No valid file UIDs provided', $result['error']);
    }

    #[Test]
    public function executeRejectsNonFileField(): void
    {
        $result = json_decode($this->tool()->execute('tt_content', 1, 'header', '42'), true);

        self::assertIsArray($result);
        self::assertStringContainsString('not a file field', (string) ($result['error'] ?? ''));
        self::assertSame(['image', 'assets'], $result['availableFileFields']);
    }

    #[Test]
    public function executeCreatesFileReferences(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService
            ->expects(self::once())
            ->method('createFileReferences')
            ->with('tt_content', 10, 'image', [42, 43], 1)
            ->willReturn([501, 502]);

        $recordService = $this->record(['uid' => 10, 'pid' => 1, 'deleted' => 0]);
        $recordService
            ->method('findFileReferences')
            ->with('tt_content', 10, 'image')
            ->willReturn([
                ['uid' => 501],
                ['uid' => 502],
            ]);

        $tool = $this->tool($recordService, $this->files([42 => [], 43 => []]), $dataHandlerService, $this->tca(['image']));

        $result = json_decode($tool->execute('tt_content', 10, 'image', '42, 43'), true);

        self::assertIsArray($result);
        self::assertSame('tt_content', $result['table']);
        self::assertSame(10, $result['uid']);
        self::assertSame('image', $result['fieldName']);
        self::assertSame(2, $result['referencesCreated']);
        self::assertSame([501, 502], $result['referenceUids']);
        self::assertSame([42, 43], $result['fileUids']);
        self::assertSame(2, $result['parentFieldCount']);
    }

    #[Test]
    public function executeRejectsMissingOrDeletedRecord(): void
    {
        $tool = $this->tool($this->record(['uid' => 128, 'pid' => 6, 'deleted' => 1]), null, null, $this->tca(['image']));

        $result = json_decode($tool->execute('tt_content', 128, 'image', '5'), true);

        self::assertIsArray($result);
        self::assertStringContainsString('missing or deleted', (string) ($result['error'] ?? ''));
    }

    #[Test]
    public function executeDoesNotAttachAFileMissingFromDisk(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');
        $tool = $this->tool(
            $this->record(['uid' => 244, 'pid' => 198, 'deleted' => 0, 'CType' => 'textmedia']),
            $this->files([3 => ['name' => 'cake.png', 'exists' => false]]),
            $dataHandlerService,
        );

        $result = json_decode($tool->execute('tt_content', 244, 'assets', '3'), true);

        self::assertStringContainsString('cake.png (sys_file uid 3) is missing', (string) ($result['error'] ?? ''));
    }

    #[Test]
    public function planListsTheFileNamesSoApplyReinvokesTheTool(): void
    {
        $tool = $this->tool(
            $this->record(['uid' => 477, 'pid' => 154, 'deleted' => 0, 'CType' => 'textmedia']),
            $this->files([87 => ['name' => 'lighthouse.jpg']]),
            null,
            $this->tca(['assets']),
        );

        $plan = $tool->plan([
            'table' => 'tt_content',
            'uid' => 477,
            'fieldName' => 'assets',
            'fileUids' => '87',
        ]);

        self::assertSame(McpConfirmationPlanBuilder::PLAN_KIND_TOOL_CONFIRMATION, $plan->context['planKind'] ?? null);
        self::assertSame(
            ['table' => 'tt_content', 'uid' => 477, 'fieldName' => 'assets', 'fileUids' => '87'],
            $plan->context['arguments'] ?? null,
        );
        self::assertStringContainsString('Attach file(s) lighthouse.jpg (uid 87)', (string) ($plan->context['summary'] ?? ''));
    }

    #[Test]
    public function planRejectsARecordThatDoesNotExist(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no tt_content record with uid 128');
        $this->tool($recordService)->plan(['table' => 'tt_content', 'uid' => 128, 'fieldName' => 'image', 'fileUids' => '5']);
    }

    #[Test]
    public function planRejectsAFieldThatIsNoFileField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File fields: image, assets');
        $this->tool()->plan(['table' => 'tt_content', 'uid' => 7, 'fieldName' => '_reference', 'fileUids' => '5']);
    }

    #[Test]
    public function planRejectsAFileNameInsteadOfAUid(): void
    {
        $tool = $this->tool($this->record(['uid' => 168, 'pid' => 198, 'deleted' => 0, 'CType' => 'image']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"cake.png" is not a sys_file uid');
        $tool->plan(['table' => 'tt_content', 'uid' => 168, 'fieldName' => 'image', 'fileUids' => 'cake.png']);
    }

    #[Test]
    public function planRejectsAnUnknownFileUid(): void
    {
        $tool = $this->tool($this->record(['uid' => 168, 'pid' => 198, 'deleted' => 0, 'CType' => 'image']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no file with sys_file uid 99999');
        $tool->plan(['table' => 'tt_content', 'uid' => 168, 'fieldName' => 'image', 'fileUids' => '99999']);
    }

    #[Test]
    public function planRejectsAFileMissingFromDisk(): void
    {
        $tool = $this->tool(
            $this->record(['uid' => 168, 'pid' => 198, 'deleted' => 0, 'CType' => 'image']),
            $this->files([3 => ['name' => 'cake.png', 'exists' => false]]),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The file cake.png (sys_file uid 3) is missing from the storage');
        $tool->plan(['table' => 'tt_content', 'uid' => 168, 'fieldName' => 'image', 'fileUids' => '3']);
    }

    #[Test]
    public function planRejectsAFileFieldTheContentTypeDoesNotShow(): void
    {
        $tool = $this->tool(
            $this->record(['uid' => 168, 'pid' => 198, 'deleted' => 0, 'CType' => 'image']),
            $this->files([145 => []]),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "assets" is not used by tt_content uid 168 (CType "image"). Use fieldName "image".');
        $tool->plan(['table' => 'tt_content', 'uid' => 168, 'fieldName' => 'assets', 'fileUids' => '145']);
    }

    #[Test]
    public function referencesAreStoredOnThePageOfTheirRecord(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())->method('createFileReferences')
            ->with('tt_content', 812, 'assets', [5], 128)
            ->willReturn([900]);
        $tool = $this->tool(
            $this->record(['uid' => 812, 'pid' => 128, 'deleted' => 0, 'CType' => 'textmedia']),
            $this->files([5 => []]),
            $dataHandlerService,
            $this->tca(['assets']),
        );

        $result = json_decode($tool->execute('tt_content', 812, 'assets', '5'), true);

        self::assertSame([900], $result['referenceUids'] ?? null);
    }

    #[Test]
    public function theAltTextIsShownOnTheCardAndStoredOnTheReference(): void
    {
        $tool = $this->tool(
            $this->record(['uid' => 172, 'pid' => 198, 'deleted' => 0, 'CType' => 'textmedia']),
            $this->files([145 => ['name' => 'lake.jpg']]),
            null,
            $this->tca(['assets']),
        );

        $plan = $tool->plan([
            'table' => 'tt_content',
            'uid' => 172,
            'fieldName' => 'assets',
            'fileUids' => '145',
            'alternative' => '  Snowy mountain lake at sunrise ',
            'title' => '',
        ]);

        self::assertSame('Snowy mountain lake at sunrise', $plan->context['arguments']['alternative'] ?? null);
        self::assertArrayNotHasKey('title', $plan->context['arguments'] ?? []);
        self::assertStringContainsString('with alt text "Snowy mountain lake at sunrise"', (string) ($plan->context['summary'] ?? ''));

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())->method('createFileReferences')
            ->with('tt_content', 172, 'assets', [145], 198, ['alternative' => 'Snowy mountain lake at sunrise'])
            ->willReturn([78]);
        $tool = $this->tool(
            $this->record(['uid' => 172, 'pid' => 198, 'deleted' => 0, 'CType' => 'textmedia']),
            $this->files([145 => ['name' => 'lake.jpg']]),
            $dataHandlerService,
            $this->tca(['assets']),
        );

        $result = json_decode($tool->execute('tt_content', 172, 'assets', '145', 'Snowy mountain lake at sunrise'), true);

        self::assertSame('Snowy mountain lake at sunrise', $result['alternative'] ?? null);
    }
}
