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

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Tool\Content\ContentMoveTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ContentMoveToolGuardTest extends TestCase
{
    #[Test]
    public function planRejectsTargetZero(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findExistingUids')->willReturn([20]);
        $records->method('findByUid')->willReturn(['header' => 'Welcome', 'pid' => 7]);

        $tool = new ContentMoveTool(
            $this->createMock(DataHandlerService::class),
            $records,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('target');
        $tool->plan(['uid' => 20, 'target' => 0]);
    }

    #[Test]
    public function executeRejectsTargetZero(): void
    {
        $dataHandler = $this->createMock(DataHandlerService::class);
        $dataHandler->expects(self::never())->method('moveRecord');

        $tool = new ContentMoveTool(
            $dataHandler,
            $this->createMock(RecordService::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $tool->execute(20, 0);
    }

    #[Test]
    public function aPageUidIsPlannedAsAPageMove(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->method('findExistingUids')->willReturnCallback(
            static fn(string $table): array => $table === 'pages' ? [80] : [],
        );
        $records->method('findByUid')->willReturn(['title' => 'Page between 1 and 2', 'pid' => 1]);

        $plan = (new ContentMoveTool($this->createMock(DataHandlerService::class), $records))
            ->plan(['uid' => 80, 'target' => -68]);

        self::assertSame('pages_move', $plan->toolName);
        self::assertSame('pages', $plan->fields[0]->table);
        self::assertSame(-68, $plan->context['target']);
    }
}
