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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Pages;

use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Pages\PagesGetTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PagesGetToolTest extends TestCase
{
    #[Test]
    public function selectFieldsLimitsRequestedColumns(): void
    {
        $tca = $this->createMock(TcaSchemaService::class);
        $tca->method('getTranslationConfig')->with('pages')->willReturn([
            'languageField' => 'sys_language_uid',
            'transOrigPointerField' => 'l10n_parent',
        ]);
        $tca->method('getReadFields')->with('pages')->willReturn([
            'title',
            'categories',
            'authors',
            'tags',
            'og_image',
        ]);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with(
                'pages',
                1739,
                self::callback(static function (array $fields): bool {
                    $unique = array_values(array_unique($fields));
                    sort($unique);

                    return $unique === ['l10n_parent', 'pid', 'sys_language_uid', 'title', 'uid'];
                }),
            )
            ->willReturn([
                'uid' => 1739,
                'pid' => 1,
                'title' => 'Live verify',
                'sys_language_uid' => 1,
            ]);

        $tool = new PagesGetTool($recordService, $tca, $this->accessibleGate());
        $decoded = json_decode($tool->execute(1739, 'title'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1739, $decoded['uid']);
        self::assertSame('Live verify', $decoded['title']);
        self::assertArrayNotHasKey('categories', $decoded);
    }

    #[Test]
    public function emptySelectFieldsUsesFullReadFieldSet(): void
    {
        $tca = $this->createMock(TcaSchemaService::class);
        $tca->method('getTranslationConfig')->with('pages')->willReturn([
            'languageField' => null,
            'transOrigPointerField' => null,
        ]);
        $tca->method('getReadFields')->with('pages')->willReturn(['title', 'categories']);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('pages', 5, ['title', 'categories'])
            ->willReturn(['uid' => 5, 'title' => 'Home', 'categories' => '126']);

        $tool = new PagesGetTool($recordService, $tca, $this->accessibleGate());
        $decoded = json_decode($tool->execute(5), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('126', $decoded['categories']);
    }

    #[Test]
    public function pageIdAliasIsUsedWhenUidOmitted(): void
    {
        $tca = $this->createMock(TcaSchemaService::class);
        $tca->method('getTranslationConfig')->with('pages')->willReturn([
            'languageField' => null,
            'transOrigPointerField' => null,
        ]);
        $tca->method('getReadFields')->with('pages')->willReturn(['title']);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('pages', 42, ['title'])
            ->willReturn(['uid' => 42, 'title' => 'About']);

        $tool = new PagesGetTool($recordService, $tca, $this->accessibleGate());
        $decoded = json_decode($tool->execute(0, '', 42), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $decoded['uid']);
        self::assertSame('About', $decoded['title']);
    }

    #[Test]
    public function missingUidAndPageIdReturnsError(): void
    {
        $tool = new PagesGetTool(
            $this->createMock(RecordService::class),
            $this->createMock(TcaSchemaService::class),
            $this->accessibleGate(),
        );
        $decoded = json_decode($tool->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Missing required argument: uid (or pageId)', $decoded['error']);
    }

    #[Test]
    public function pageOutsideEditorAccessReturnsAccessDeniedWithoutLoadingTheRecord(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('findByUid');
        $recordService->expects(self::never())->method('findTranslations');

        $gate = $this->createMock(PageAccessService::class);
        $gate->method('canReadPage')->with(77)->willReturn(false);

        $tool = new PagesGetTool($recordService, $this->createMock(TcaSchemaService::class), $gate);
        $decoded = json_decode($tool->execute(77), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(PageAccessService::ACCESS_DENIED_MESSAGE, $decoded['error']);
        self::assertArrayNotHasKey('title', $decoded);
    }

    private function accessibleGate(): PageAccessService
    {
        $gate = $this->createMock(PageAccessService::class);
        $gate->method('canReadPage')->willReturn(true);

        return $gate;
    }
}
