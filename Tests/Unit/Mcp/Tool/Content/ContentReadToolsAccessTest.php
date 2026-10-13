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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Content;

use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Content\ContentGetTool;
use NITSAN\NsT3AF\Mcp\Tool\Content\ContentListTool;
use NITSAN\NsT3AF\Mcp\Tool\Search\ContentSearchTool;
use NITSAN\NsT3AF\Mcp\Tool\Search\RecordSearchTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Read tools answer "You don't have access to this page." for pages outside the editor's mount and
 * never touch the data (ticket 14zervyu2j7).
 *
 * @internal
 */
final class ContentReadToolsAccessTest extends TestCase
{
    private const OUTSIDE_PAGE = 225;
    private const MOUNTED_PAGE = 224;

    #[Test]
    public function contentListRefusesAnOutsidePage(): void
    {
        $records = $this->untouchedRecords();
        $tool = new ContentListTool($records, $this->untouchedSchema(), $this->access());

        $decoded = $this->decode($tool->execute(self::OUTSIDE_PAGE));

        self::assertSame(PageAccessService::ACCESS_DENIED_MESSAGE, $decoded['error']);
        self::assertSame(self::OUTSIDE_PAGE, $decoded['pageId']);
        self::assertArrayNotHasKey('records', $decoded);
    }

    #[Test]
    public function contentListStillListsAMountedPage(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())->method('findByPid')->willReturn(['records' => [['uid' => 1]], 'total' => 1]);
        $tool = new ContentListTool($records, $this->schema(), $this->access());

        $decoded = $this->decode($tool->execute(self::MOUNTED_PAGE));

        self::assertArrayNotHasKey('error', $decoded);
        self::assertSame(1, $decoded['total']);
    }

    #[Test]
    public function contentSearchRefusesAnOutsidePage(): void
    {
        $tool = new ContentSearchTool($this->untouchedRecords(), $this->untouchedSchema(), $this->access());

        $decoded = $this->decode($tool->execute('SECRET', 20, 0, self::OUTSIDE_PAGE));

        self::assertSame(PageAccessService::ACCESS_DENIED_MESSAGE, $decoded['error']);
        self::assertSame(self::OUTSIDE_PAGE, $decoded['pageId']);
    }

    #[Test]
    public function recordSearchRefusesAnOutsidePageForPageContentTables(): void
    {
        $permissions = $this->createMock(\NITSAN\NsT3AF\Mcp\Service\PermissionService::class);
        $permissions->expects(self::once())->method('assertCanSelectTable')->with('tt_content');
        $tool = new RecordSearchTool(
            $this->untouchedRecords(),
            $this->untouchedSchema(),
            $this->access(),
            $permissions,
        );

        try {
            $tool->execute('tt_content', '{"header":"SECRET"}', 20, 0, self::OUTSIDE_PAGE);
            self::fail('Expected page access refusal.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(PageAccessService::ACCESS_DENIED_MESSAGE, $exception->getMessage());
        }
    }

    #[Test]
    public function contentGetExplainsAMissingElementForRestrictedEditors(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(null);
        $tool = new ContentGetTool($records, $this->schema(), $this->access(unrestricted: false));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("don't have access to its page");
        $tool->execute(714);
    }

    #[Test]
    public function contentGetKeepsTheShortMessageForAdmins(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturn(null);
        $tool = new ContentGetTool($records, $this->schema(), $this->access(unrestricted: true));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Content element not found');
        $tool->execute(714);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return PageAccessService&MockObject */
    private function access(bool $unrestricted = false): PageAccessService
    {
        $access = $this->createMock(PageAccessService::class);
        $access->method('isUnrestricted')->willReturn($unrestricted);
        $access->method('canReadPage')->willReturnCallback(
            static fn(int $pageId): bool => $unrestricted || $pageId === self::MOUNTED_PAGE,
        );

        return $access;
    }

    /** @return RecordService&MockObject */
    private function untouchedRecords(): RecordService
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::never())->method('findByPid');
        $records->expects(self::never())->method('search');
        $records->expects(self::never())->method('findByUid');

        return $records;
    }

    /** @return TcaSchemaService&MockObject */
    private function untouchedSchema(): TcaSchemaService
    {
        $schema = $this->createMock(TcaSchemaService::class);
        $schema->expects(self::never())->method('getReadFields');
        $schema->expects(self::never())->method('getListFields');

        return $schema;
    }

    /** @return TcaSchemaService&MockObject */
    private function schema(): TcaSchemaService
    {
        $schema = $this->createMock(TcaSchemaService::class);
        $schema->method('getTranslationConfig')->willReturn([
            'languageField' => null,
            'transOrigPointerField' => null,
            'translationSource' => null,
        ]);
        $schema->method('getReadFields')->willReturn(['uid', 'pid', 'header']);
        $schema->method('getListFields')->willReturn(['uid', 'pid', 'header']);

        return $schema;
    }
}
