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

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Pages\PagesMoveTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PagesMoveToolTest extends TestCase
{
    #[Test]
    public function afterUidPlacesThePageDirectlyAfterThatPage(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findExistingUids')->willReturn([80]);
        $records->method('findByUid')->willReturn(['title' => 'Page between 1 and 2', 'pid' => 1]);
        $records->expects(self::once())->method('assertInsertAfterExists')->with('pages', 68);

        $plan = (new PagesMoveTool($this->createMock(DataHandlerService::class), $records))
            ->plan(['uid' => 80, 'afterUid' => 68]);

        self::assertSame('pages_move', $plan->toolName);
        self::assertSame('move', $plan->action);
        self::assertSame(-68, $plan->context['target']);
        self::assertSame('pages', $plan->fields[0]->table);
    }

    #[Test]
    public function afterUidWinsWhenTargetPidIsAlsoSent(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findExistingUids')->willReturn([48]);
        $records->method('findByUid')->willReturnCallback(
            static fn(string $table, int $uid, array $fields): array => $uid === 75
                ? ['title' => 'Sample', 'pid' => 68]
                : ['title' => 'test', 'pid' => 1],
        );
        $records->expects(self::once())->method('assertInsertAfterExists')->with('pages', 75);

        $plan = (new PagesMoveTool($this->createMock(DataHandlerService::class), $records))
            ->plan(['uid' => 48, 'afterUid' => 75, 'targetPid' => 1]);

        self::assertSame(-75, $plan->context['target']);
        self::assertSame('after Sample [75]', $plan->fields[0]->proposedValue);
    }

    #[Test]
    public function beforeUidPlacesThePageDirectlyBeforeThatPage(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())->method('targetBeforePage')->with(69, 80)->willReturn(-68);
        $records->method('findExistingUids')->willReturn([80]);
        $records->method('findByUid')->willReturnCallback(
            static fn(string $table, int $uid, array $fields): array => $uid === 69
                ? ['title' => 'Page 2', 'pid' => 1]
                : ['title' => 'Page between 1 and 2', 'pid' => 1],
        );
        $records->expects(self::once())->method('assertInsertAfterExists')->with('pages', 68);

        $plan = (new PagesMoveTool($this->createMock(DataHandlerService::class), $records))
            ->plan(['uid' => 80, 'beforeUid' => 69]);

        self::assertSame(-68, $plan->context['target']);
        self::assertSame('before Page 2 [69]', $plan->fields[0]->proposedValue);
    }

    #[Test]
    public function aMissingPageIsRefused(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findExistingUids')->willReturn([]);

        $tool = new PagesMoveTool($this->createMock(DataHandlerService::class), $records);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Page not found');
        $tool->plan(['uid' => 80, 'afterUid' => 68]);
    }
}
