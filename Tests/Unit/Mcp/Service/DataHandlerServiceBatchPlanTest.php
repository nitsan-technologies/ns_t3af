<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A batch delete / move plan changes every record it names, not only the first.
 *
 * @internal
 */
final class DataHandlerServiceBatchPlanTest extends TestCase
{
    #[Test]
    public function batchDeleteDeletesEveryRecord(): void
    {
        $deleted = [];
        $service = $this->createPartialMock(DataHandlerService::class, ['deleteRecord']);
        $service->method('deleteRecord')->willReturnCallback(
            static function (string $table, int $uid) use (&$deleted): void {
                $deleted[] = $table . '#' . $uid;
            },
        );

        $plan = new ToolPlan('delete', 'content_delete', [$this->field(10), $this->field(11), $this->field(12)]);
        $result = $service->applyFilteredPlan($plan, ['tt_content:10:_record', 'tt_content:11:_record', 'tt_content:12:_record'], 'c1');

        self::assertSame(['tt_content#10', 'tt_content#11', 'tt_content#12'], $deleted);
        self::assertCount(3, $result['affected']);
    }

    #[Test]
    public function batchMoveMovesEveryRecord(): void
    {
        $moved = [];
        $service = $this->createPartialMock(DataHandlerService::class, ['moveRecord']);
        $service->method('moveRecord')->willReturnCallback(
            static function (string $table, int $uid, int $target) use (&$moved): void {
                $moved[] = $table . '#' . $uid . '>' . $target;
            },
        );

        $plan = new ToolPlan('move', 'content_move', [$this->field(10), $this->field(11)], ['target' => 0]);
        $result = $service->applyFilteredPlan($plan, ['tt_content:10:_record', 'tt_content:11:_record'], 'c2');

        self::assertSame(['tt_content#10>0', 'tt_content#11>0'], $moved);
        self::assertSame([['table' => 'tt_content', 'uid' => 10], ['table' => 'tt_content', 'uid' => 11]], $result['affected']);
    }

    #[Test]
    public function aCopyReportsTheNewRecordNotTheSource(): void
    {
        $service = $this->createPartialMock(DataHandlerService::class, ['copyRecord']);
        $service->method('copyRecord')->willReturn(500);

        $plan = new ToolPlan('copy', 'pages_copy', [new ToolPlanField('pages:5:_record', 'pages', 5, '_record', null, 0)], ['target' => 0]);
        $result = $service->applyFilteredPlan($plan, ['pages:5:_record'], 'c3');

        self::assertSame([['table' => 'pages', 'uid' => 500]], $result['affected']);
    }

    private function field(int $uid): ToolPlanField
    {
        return new ToolPlanField('tt_content:' . $uid . ':_record', 'tt_content', $uid, '_record', null, null);
    }
}
