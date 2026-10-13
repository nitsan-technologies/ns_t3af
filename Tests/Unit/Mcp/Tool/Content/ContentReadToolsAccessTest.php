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
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->with('pages', self::OUTSIDE_PAGE, ['uid', 'l10n_parent', 'sys_language_uid'])->willReturn(null);
        $records->expects(self::never())->method('findByPid');
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
        $records->method('findByUid')->with('pages', self::MOUNTED_PAGE, ['uid', 'l10n_parent', 'sys_language_uid'])->willReturn(
            ['uid' => self::MOUNTED_PAGE, 'l10n_parent' => 0, 'sys_language_uid' => 0],
        );
        $records->expects(self::once())->method('findByPid')->with(
            'tt_content',
            self::MOUNTED_PAGE,
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
        )->willReturn(['records' => [['uid' => 1]], 'total' => 1]);
        $tool = new ContentListTool($records, $this->schema(), $this->access());

        $decoded = $this->decode($tool->execute(self::MOUNTED_PAGE));

        self::assertArrayNotHasKey('error', $decoded);
        self::assertSame(1, $decoded['total']);
    }

    #[Test]
    public function contentListRemapsAnOverlayPageUidToTheDefaultLanguagePid(): void
    {
        $overlayUid = 70;
        $defaultPid = 2;
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->with('pages', $overlayUid, ['uid', 'l10n_parent', 'sys_language_uid'])->willReturn(
            ['uid' => $overlayUid, 'l10n_parent' => $defaultPid, 'sys_language_uid' => 1],
        );
        $schema = $this->createMock(TcaSchemaService::class);
        $schema->method('getTranslationConfig')->willReturn([
            'languageField' => 'sys_language_uid',
            'transOrigPointerField' => 'l18n_parent',
            'translationSource' => null,
        ]);
        $schema->method('getListFields')->willReturn(['uid', 'pid', 'header']);
        $records->expects(self::once())->method('findByPid')->with(
            'tt_content',
            $defaultPid,
            self::anything(),
            self::anything(),
            self::anything(),
            1,
            'sys_language_uid',
        )->willReturn(['records' => [['uid' => 753]], 'total' => 1]);
        $access = $this->createMock(PageAccessService::class);
        $access->method('canReadPage')->with($defaultPid)->willReturn(true);
        $tool = new ContentListTool($records, $schema, $access);

        // Default sysLanguageUid=-1: overlay remap must filter to that page's language (1).
        $decoded = $this->decode($tool->execute($overlayUid));

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

    #[Test]
    public function contentGetRejectsAPageUidWithAClearMessage(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findByUid')->willReturnCallback(static function (string $table, int $uid): ?array {
            if ($table === 'tt_content') {
                return null;
            }
            if ($table === 'pages' && $uid === 70) {
                return ['uid' => 70, 'l10n_parent' => 2];
            }

            return null;
        });
        $tool = new ContentGetTool($records, $this->schema(), $this->access(unrestricted: true));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Uid 70 is a page, not a content element');
        $tool->execute(70);
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
