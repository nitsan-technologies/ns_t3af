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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\RecordsApply;

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsUndoPlanner;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsUndoSchemaInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RecordsUndoPlannerTest extends TestCase
{
    private RecordsUndoSchemaInfo&MockObject $schema;

    private RecordsUndoPlanner $planner;

    protected function setUp(): void
    {
        $GLOBALS['TCA']['tt_content']['ctrl']['sortby'] = 'sorting';
        $GLOBALS['TCA']['pages']['ctrl']['sortby'] = 'sorting';
        $GLOBALS['TCA']['tx_nst3af_no_sort']['ctrl'] = [];

        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('recordsOnPage')->willReturn([]);
        $this->schema->method('fieldKind')->willReturnCallback(
            static fn(string $table, string $field): int => match ($field) {
                'image' => RecordsUndoSchemaInfo::FIELD_RELATION,
                'tstamp' => RecordsUndoSchemaInfo::FIELD_IGNORE,
                default => RecordsUndoSchemaInfo::FIELD_RESTORE,
            },
        );
        $this->planner = new RecordsUndoPlanner($this->schema);
    }

    #[Test]
    public function aCreatedRecordIsDeletedAndItsOwnFieldRowsAreIgnored(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 1, 'tt_content', 10),
            $this->row(2, 2, 'tt_content', 10, ['oldRecord' => ['header' => ''], 'newRecord' => ['header' => 'Hi']]),
        ]);

        self::assertSame(['tt_content' => [10 => ['delete' => 1]]], $plan['cmdmap']);
        self::assertSame([], $plan['datamap']);
        self::assertSame(['tt_content' => [10]], $plan['created']);
        self::assertSame(1, $plan['count']);
    }

    #[Test]
    public function aDeletedRecordIsUndeleted(): void
    {
        $plan = $this->planner->plan([$this->row(1, 4, 'tt_content', 7)]);

        self::assertSame(['tt_content' => [7 => ['undelete' => 1]]], $plan['cmdmap']);
        self::assertSame([], $plan['created']);
    }

    #[Test]
    public function theOldestOldValueOfAFieldWins(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 2, 'tt_content', 7, ['oldRecord' => ['header' => 'Original', 'subheader' => 'S'], 'newRecord' => ['header' => 'Second', 'subheader' => 'S2']]),
            $this->row(2, 2, 'tt_content', 7, ['oldRecord' => ['header' => 'Second'], 'newRecord' => ['header' => 'Third']]),
        ]);

        self::assertSame(['tt_content' => [7 => ['header' => 'Original', 'subheader' => 'S']]], $plan['datamap']);
        self::assertSame([], $plan['cmdmap']);
    }

    #[Test]
    public function relationFieldsAreReportedAndSystemFieldsAreLeftAlone(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 2, 'tt_content', 7, ['oldRecord' => ['header' => 'A', 'image' => 2, 'tstamp' => 5], 'newRecord' => []]),
        ]);

        self::assertSame(['tt_content' => [7 => ['header' => 'A']]], $plan['datamap']);
        self::assertSame(
            [['table' => 'tt_content', 'id' => 7, 'fields' => ['image'], 'reason' => 'relation fields (files, inline records, categories) are not restored']],
            $plan['notRestored'],
        );
    }

    #[Test]
    public function aRecordCreatedAndDeletedInTheSameBatchNeedsNothing(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 1, 'tt_content', 7),
            $this->row(2, 4, 'tt_content', 7),
        ]);

        self::assertSame([], $plan['cmdmap']);
        self::assertSame(0, $plan['count']);
    }

    #[Test]
    public function aRecordThatExistedBeforeAndWasDeletedAndUndeletedHasItsFieldsRestored(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 4, 'tt_content', 7),
            $this->row(2, 5, 'tt_content', 7),
            $this->row(3, 2, 'tt_content', 7, ['oldRecord' => ['header' => 'Old'], 'newRecord' => ['header' => 'New']]),
        ]);

        self::assertSame(['tt_content' => [7 => ['header' => 'Old']]], $plan['datamap']);
        self::assertSame([], $plan['cmdmap']);
    }

    #[Test]
    public function aRecordChangedAndDeletedInOneBatchComesBackWithItsFieldsNotRestored(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 2, 'tt_content', 7, ['oldRecord' => ['header' => 'Old'], 'newRecord' => ['header' => 'New']]),
            $this->row(2, 4, 'tt_content', 7),
        ]);

        self::assertSame(['tt_content' => [7 => ['undelete' => 1]]], $plan['cmdmap']);
        self::assertSame([], $plan['datamap']);
        self::assertSame('changed and deleted in the same batch', $plan['notRestored'][0]['reason']);
        self::assertSame(['header'], $plan['notRestored'][0]['fields']);
    }

    #[Test]
    public function aMovedRecordGoesBackToWhereTheOldestMoveTookItFrom(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 7, ['oldData' => ['pid' => 4, 'sorting' => 256], 'newData' => ['pid' => 5]]),
            $this->row(2, 3, 'tt_content', 7, ['oldData' => ['pid' => 5, 'sorting' => 512], 'newData' => ['pid' => 6]]),
        ]);

        // Oldest move wins; empty old page → top of pid 4.
        self::assertSame(['tt_content' => [7 => ['move' => 4]]], $plan['cmdmap']);
    }

    #[Test]
    public function consecutiveCoMoversAreRestoredInOldSortingOrderWithChaining(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 20, ['oldData' => ['pid' => 1, 'sorting' => 512], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 10, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 30, ['oldData' => ['pid' => 1, 'sorting' => 768], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 1],
                    20 => ['move' => -10],
                    30 => ['move' => -20],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function aCoMoverGroupChainsAfterTheUnmovedPredecessorStillOnThePage(): void
    {
        // M1 stayed on the page (uid 1); only M2 and M3 left.
        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('fieldKind')->willReturn(RecordsUndoSchemaInfo::FIELD_RESTORE);
        $this->schema->method('recordsOnPage')->willReturn([['uid' => 1, 'sorting' => 256]]);
        $this->planner = new RecordsUndoPlanner($this->schema);

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 3, ['oldData' => ['pid' => 1, 'sorting' => 768], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 2, ['oldData' => ['pid' => 1, 'sorting' => 512], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    2 => ['move' => -1],
                    3 => ['move' => -2],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function interleavedUnmovedRecordsAreKeptBetweenRestoredMovers(): void
    {
        // Old page: E1(10@256), E1b(15@384), E2(20@512), E3(30@768), E4(40@1024). Moved: E1, E2, E3.
        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('fieldKind')->willReturn(RecordsUndoSchemaInfo::FIELD_RESTORE);
        $this->schema->method('recordsOnPage')->willReturn([
            ['uid' => 15, 'sorting' => 384],
            ['uid' => 40, 'sorting' => 1024],
        ]);
        $this->planner = new RecordsUndoPlanner($this->schema);

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 10, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 20, ['oldData' => ['pid' => 1, 'sorting' => 512], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 30, ['oldData' => ['pid' => 1, 'sorting' => 768], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 1],
                    20 => ['move' => -15],
                    30 => ['move' => -20],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function moversAtTheStartOfThePageChainAmongThemselves(): void
    {
        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('fieldKind')->willReturn(RecordsUndoSchemaInfo::FIELD_RESTORE);
        $this->schema->method('recordsOnPage')->willReturn([
            ['uid' => 40, 'sorting' => 1024],
            ['uid' => 50, 'sorting' => 1280],
        ]);
        $this->planner = new RecordsUndoPlanner($this->schema);

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 10, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 20, ['oldData' => ['pid' => 1, 'sorting' => 512], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 30, ['oldData' => ['pid' => 1, 'sorting' => 768], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 1],
                    20 => ['move' => -10],
                    30 => ['move' => -20],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function moversAtTheEndOfThePageFollowTheLastUnmovedRecord(): void
    {
        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('fieldKind')->willReturn(RecordsUndoSchemaInfo::FIELD_RESTORE);
        $this->schema->method('recordsOnPage')->willReturn([
            ['uid' => 10, 'sorting' => 256],
            ['uid' => 20, 'sorting' => 512],
        ]);
        $this->planner = new RecordsUndoPlanner($this->schema);

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 30, ['oldData' => ['pid' => 1, 'sorting' => 768], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 40, ['oldData' => ['pid' => 1, 'sorting' => 1024], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 50, ['oldData' => ['pid' => 1, 'sorting' => 1280], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    30 => ['move' => -20],
                    40 => ['move' => -30],
                    50 => ['move' => -40],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function alternatingMovedAndUnmovedRecordsRestoreAfterEachUnmovedPredecessor(): void
    {
        // E1, X, E2, Y, E3 — movers E1/E2/E3; X=11, Y=21 still on page.
        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('fieldKind')->willReturn(RecordsUndoSchemaInfo::FIELD_RESTORE);
        $this->schema->method('recordsOnPage')->willReturn([
            ['uid' => 11, 'sorting' => 384],
            ['uid' => 21, 'sorting' => 640],
        ]);
        $this->planner = new RecordsUndoPlanner($this->schema);

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 10, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 20, ['oldData' => ['pid' => 1, 'sorting' => 512], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 30, ['oldData' => ['pid' => 1, 'sorting' => 768], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 1],
                    20 => ['move' => -11],
                    30 => ['move' => -21],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function moversFromTwoOldPagesAreRestoredPerPage(): void
    {
        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('fieldKind')->willReturn(RecordsUndoSchemaInfo::FIELD_RESTORE);
        $this->schema->method('recordsOnPage')->willReturnCallback(
            static fn(string $table, int $pid): array => match ($pid) {
                1 => [['uid' => 15, 'sorting' => 384]],
                2 => [],
                default => [],
            },
        );
        $this->planner = new RecordsUndoPlanner($this->schema);

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 10, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 20, ['oldData' => ['pid' => 1, 'sorting' => 512], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 30, ['oldData' => ['pid' => 2, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            $this->row(4, 3, 'tt_content', 40, ['oldData' => ['pid' => 2, 'sorting' => 512], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 1],
                    20 => ['move' => -15],
                    30 => ['move' => 2],
                    40 => ['move' => -30],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function equalOldSortingIsOrderedByUidForAStableMerge(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 20, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 10, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 1],
                    20 => ['move' => -10],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function moversWithoutOldSortingFallBackToChainingAfterTheMergedList(): void
    {
        $this->schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $this->schema->method('supportsSoftDelete')->willReturn(true);
        $this->schema->method('fieldKind')->willReturn(RecordsUndoSchemaInfo::FIELD_RESTORE);
        $this->schema->method('recordsOnPage')->willReturn([
            ['uid' => 15, 'sorting' => 384],
        ]);
        $this->planner = new RecordsUndoPlanner($this->schema);

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 10, ['oldData' => ['pid' => 1, 'sorting' => 256], 'newData' => ['pid' => 9]]),
            // No sorting in history: chain after the last known slot (E1b), not invent sorting 0.
            $this->row(2, 3, 'tt_content', 20, ['oldData' => ['pid' => 1], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 30, ['oldData' => ['pid' => 1, 'sorting' => 768], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 1],
                    30 => ['move' => -15],
                    20 => ['move' => -30],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function allMoversWithoutOldSortingChainLikeThePreviousBehaviour(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 20, ['oldData' => ['pid' => 1], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tt_content', 10, ['oldData' => ['pid' => 1], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tt_content', 30, ['oldData' => ['pid' => 1], 'newData' => ['pid' => 9]]),
        ]);

        // History order of the group (not uid): first → top, then after previous mover.
        self::assertSame(
            [
                'tt_content' => [
                    20 => ['move' => 1],
                    10 => ['move' => -20],
                    30 => ['move' => -10],
                ],
            ],
            $plan['cmdmap'],
        );
    }

    #[Test]
    public function tablesWithoutSortbyRestoreEachRecordToItsOwnOldPidWithoutChaining(): void
    {
        $this->schema->expects(self::exactly(3))->method('resolveMoveTarget')->willReturnCallback(
            static fn(string $table, int $uid, array $previous): int => (int) $previous['pid'],
        );

        $plan = $this->planner->plan([
            $this->row(1, 3, 'tx_nst3af_no_sort', 10, ['oldData' => ['pid' => 5], 'newData' => ['pid' => 9]]),
            $this->row(2, 3, 'tx_nst3af_no_sort', 20, ['oldData' => ['pid' => 5], 'newData' => ['pid' => 9]]),
            $this->row(3, 3, 'tx_nst3af_no_sort', 30, ['oldData' => ['pid' => 7], 'newData' => ['pid' => 9]]),
        ]);

        self::assertSame(
            [
                'tx_nst3af_no_sort' => [
                    10 => ['move' => 5],
                    20 => ['move' => 5],
                    30 => ['move' => 7],
                ],
            ],
            $plan['cmdmap'],
        );
        foreach ($plan['cmdmap']['tx_nst3af_no_sort'] as $command) {
            self::assertGreaterThan(0, $command['move']);
        }
    }

    #[Test]
    public function aMoveWithAnUnknownPreviousPositionIsReported(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 3, 'tt_content', 7, ['oldData' => ['pid' => -1, 'sorting' => 256], 'newData' => ['pid' => 5]]),
        ]);

        self::assertSame([], $plan['cmdmap']);
        self::assertSame('the previous position of the moved record is unknown', $plan['notRestored'][0]['reason']);
    }

    #[Test]
    public function aMoveOfARecordTheBatchCreatedIsIgnored(): void
    {
        $this->schema->expects(self::never())->method('recordsOnPage');

        $plan = $this->planner->plan([
            $this->row(1, 1, 'tt_content', 7),
            $this->row(2, 3, 'tt_content', 7, ['oldData' => ['pid' => 4], 'newData' => ['pid' => 5]]),
        ]);

        self::assertSame(['tt_content' => [7 => ['delete' => 1]]], $plan['cmdmap']);
    }

    #[Test]
    public function pagesComeFirstAndDeletesRunNewestFirst(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 1, 'pages', 20),
            $this->row(2, 1, 'tt_content', 30),
            $this->row(3, 1, 'tt_content', 31),
            $this->row(4, 1, 'pages', 21),
        ]);

        self::assertSame(['pages', 'tt_content'], array_keys($plan['cmdmap']));
        self::assertSame([21, 20], array_keys($plan['cmdmap']['pages']));
        self::assertSame([31, 30], array_keys($plan['cmdmap']['tt_content']));
    }

    #[Test]
    public function aPageIsUndeletedBeforeItsRecordsAndOldestFirst(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 4, 'tt_content', 30),
            $this->row(2, 4, 'pages', 20),
        ]);

        self::assertSame(['pages', 'tt_content'], array_keys($plan['cmdmap']));
    }

    #[Test]
    public function aTableThatRemovesRecordsForGoodCannotBeUndone(): void
    {
        $schema = $this->createMock(RecordsUndoSchemaInfo::class);
        $schema->method('supportsSoftDelete')->willReturn(false);

        $plan = (new RecordsUndoPlanner($schema))->plan([$this->row(1, 4, 'sys_file_reference', 7)]);

        self::assertCount(1, $plan['problems']);
        self::assertStringContainsString('sys_file_reference:7', $plan['problems'][0]);
        self::assertSame([], $plan['cmdmap']);
    }

    #[Test]
    public function stageChangesAndPublishingAreIgnored(): void
    {
        $plan = $this->planner->plan([
            $this->row(1, 6, 'tt_content', 7),
            $this->row(2, 7, 'tt_content', 7),
        ]);

        self::assertSame(0, $plan['count']);
    }

    /**
     * @param array<mixed> $data
     * @return array{uid: int, actiontype: int, tablename: string, recuid: int, history_data: array<mixed>}
     */
    private function row(int $uid, int $action, string $table, int $recuid, array $data = []): array
    {
        return ['uid' => $uid, 'actiontype' => $action, 'tablename' => $table, 'recuid' => $recuid, 'history_data' => $data];
    }
}
