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

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyBulkExpander;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyPreflight;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordsApplyBulkExpanderTest extends TestCase
{
    private RecordsApplyBulkExpander $expander;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TCA']['tt_content']['ctrl']['sortby'] = 'sorting';
        $GLOBALS['TCA']['pages']['ctrl']['sortby'] = 'sorting';
        $this->expander = new RecordsApplyBulkExpander();
    }

    #[Test]
    public function noBulkLeavesTheMapsAlone(): void
    {
        $data = ['tt_content' => ['NEWc' => ['pid' => 1]]];
        $cmd = ['tt_content' => [7 => ['delete' => 1]]];

        self::assertSame([$data, $cmd], $this->expander->expand([], $data, $cmd));
    }

    #[Test]
    public function setBecomesOneDatamapEntryPerUid(): void
    {
        [$data, $cmd] = $this->expander->expand(
            [['table' => 'tt_content', 'uids' => [1, '2', 3], 'set' => ['hidden' => 1]]],
            [],
            [],
        );

        self::assertSame(['tt_content' => [1 => ['hidden' => 1], 2 => ['hidden' => 1], 3 => ['hidden' => 1]]], $data);
        self::assertSame([], $cmd);
    }

    #[Test]
    public function deleteAndMoveBecomeCmdmapEntries(): void
    {
        [$data, $cmd] = $this->expander->expand(
            [
                ['table' => 'tt_content', 'uids' => [4, 5], 'delete' => true],
                ['table' => 'tt_content', 'uids' => [6], 'move' => '12'],
                ['table' => 'pages', 'uids' => [9], 'move' => -3],
            ],
            [],
            [],
        );

        self::assertSame([], $data);
        self::assertSame(
            [
                'tt_content' => [4 => ['delete' => 1], 5 => ['delete' => 1], 6 => ['move' => 12]],
                'pages' => [9 => ['move' => -3]],
            ],
            $cmd,
        );
    }

    #[Test]
    public function aMultiUidMoveChainsAfterThePreviousRecord(): void
    {
        [, $cmd] = $this->expander->expand(
            [['table' => 'tt_content', 'uids' => [10, 20, 30], 'move' => 5]],
            [],
            [],
        );

        self::assertSame(
            [
                'tt_content' => [
                    10 => ['move' => 5],
                    20 => ['move' => -10],
                    30 => ['move' => -20],
                ],
            ],
            $cmd,
        );
    }

    #[Test]
    public function pagesBulkMoveChainsToo(): void
    {
        [, $cmd] = $this->expander->expand(
            [['table' => 'pages', 'uids' => [2, 3], 'move' => 1]],
            [],
            [],
        );

        self::assertSame(
            [
                'pages' => [
                    2 => ['move' => 1],
                    3 => ['move' => -2],
                ],
            ],
            $cmd,
        );
    }

    #[Test]
    public function bulkIsMergedWithTheExplicitMaps(): void
    {
        [$data, $cmd] = $this->expander->expand(
            [['table' => 'tt_content', 'uids' => [1], 'set' => ['hidden' => 1]]],
            ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'A'], 9 => ['header' => 'B']]],
            ['pages' => [3 => ['delete' => 1]]],
        );

        self::assertSame(['NEWc', 9, 1], array_keys($data['tt_content']));
        self::assertSame(['pages' => [3 => ['delete' => 1]]], $cmd);
    }

    #[Test]
    public function duplicateUidsInOneEntryAreCollapsed(): void
    {
        [$data] = $this->expander->expand([['table' => 'tt_content', 'uids' => [2, 2, '2'], 'set' => ['hidden' => 1]]], [], []);

        self::assertSame([2], array_keys($data['tt_content']));
    }

    #[Test]
    public function aRecordNamedTwiceInTheSameSectionIsRefused(): void
    {
        $problems = $this->problemsOf(
            [
                ['table' => 'tt_content', 'uids' => [1, 2], 'set' => ['hidden' => 1]],
                ['table' => 'tt_content', 'uids' => [2, 3], 'set' => ['hidden' => 0]],
                ['table' => 'tt_content', 'uids' => [9], 'set' => ['hidden' => 0]],
            ],
            ['tt_content' => [9 => ['header' => 'x']]],
            [],
        );

        self::assertCount(2, $problems);
        self::assertSame(1, $problems[0]['bulk']);
        self::assertStringContainsString('2', (string) $problems[0]['error']);
        self::assertSame(2, $problems[1]['bulk']);
    }

    #[Test]
    public function theSameRecordMayBeUpdatedInDataAndDeletedInCmd(): void
    {
        [$data, $cmd] = $this->expander->expand(
            [['table' => 'tt_content', 'uids' => [5], 'delete' => true]],
            ['tt_content' => [5 => ['header' => 'last words']]],
            [],
        );

        self::assertSame(['tt_content' => [5 => ['header' => 'last words']]], $data);
        self::assertSame(['tt_content' => [5 => ['delete' => 1]]], $cmd);
    }

    #[Test]
    public function exactlyOneActionIsRequired(): void
    {
        $problems = $this->problemsOf(
            [
                ['table' => 'tt_content', 'uids' => [1]],
                ['table' => 'tt_content', 'uids' => [2], 'set' => ['hidden' => 1], 'delete' => true],
            ],
            [],
            [],
        );

        self::assertCount(2, $problems);
        self::assertStringContainsString('exactly one', (string) $problems[0]['error']);
        self::assertStringContainsString('exactly one', (string) $problems[1]['error']);
    }

    #[Test]
    public function badEntriesAreNamedByTheirPosition(): void
    {
        $problems = $this->problemsOf(
            [
                'not an object',
                ['table' => 'bad table!', 'uids' => [1], 'set' => ['a' => 1]],
                ['table' => 'tt_content', 'uids' => [], 'set' => ['a' => 1]],
                ['table' => 'tt_content', 'uids' => [0, -1], 'set' => ['a' => 1]],
                ['table' => 'tt_content', 'uids' => [1], 'set' => []],
                ['table' => 'tt_content', 'uids' => [1], 'delete' => 2],
                ['table' => 'tt_content', 'uids' => [1], 'move' => 'abc'],
                ['table' => 'tt_content', 'uids' => [1], 'set' => ['a' => 1], 'extra' => 1],
            ],
            [],
            [],
        );

        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], array_column($problems, 'bulk'));
        self::assertStringContainsString('must be an object', (string) $problems[0]['error']);
        self::assertStringContainsString('"table"', (string) $problems[1]['error']);
        self::assertStringContainsString('"uids"', (string) $problems[2]['error']);
        self::assertStringContainsString('"uids"', (string) $problems[3]['error']);
        self::assertStringContainsString('"set"', (string) $problems[4]['error']);
        self::assertStringContainsString('"delete"', (string) $problems[5]['error']);
        self::assertStringContainsString('"move"', (string) $problems[6]['error']);
        self::assertSame(['extra'], $problems[7]['fields']);
    }

    #[Test]
    public function aBulkThatIsNotAListIsRefused(): void
    {
        $problems = $this->problemsOf(['table' => 'tt_content'], [], []);

        self::assertStringContainsString('must be a list', (string) $problems[0]['error']);
    }

    #[Test]
    public function tooManyUidsInOneEntryAreRefusedWithoutBeingExpanded(): void
    {
        $problems = $this->problemsOf(
            [['table' => 'tt_content', 'uids' => range(1, RecordsApplyPreflight::MAX_RECORDS + 1), 'delete' => true]],
            [],
            [],
        );

        self::assertStringContainsString('"uids"', (string) $problems[0]['error']);
    }

    #[Test]
    public function theRecordLimitCountsAllEntriesTogether(): void
    {
        $problems = $this->problemsOf(
            [
                ['table' => 'tt_content', 'uids' => range(1, 300), 'delete' => true],
                ['table' => 'tt_content', 'uids' => range(301, 600), 'move' => 5],
                ['table' => 'tt_content', 'uids' => range(601, 900), 'set' => ['hidden' => 1]],
            ],
            [],
            [],
        );

        self::assertCount(1, $problems);
        self::assertStringContainsString('Too many records in bulk', (string) $problems[0]['error']);
    }

    #[Test]
    public function problemsNeverRepeatFieldValues(): void
    {
        $problems = $this->problemsOf(
            [
                ['table' => 'tt_content', 'uids' => [1], 'set' => ['header' => 'SECRET-VALUE-1'], 'delete' => true],
                ['table' => 'tt_content', 'uids' => ['SECRET-VALUE-2'], 'set' => ['header' => 'SECRET-VALUE-3']],
            ],
            [],
            [],
        );

        self::assertStringNotContainsString('SECRET-VALUE', (string) json_encode($problems));
    }

    #[Test]
    public function unknownKeysAreCutAndStrippedBeforeTheyAreEchoed(): void
    {
        $problems = $this->problemsOf(
            [['table' => 'tt_content', 'uids' => [1], 'set' => ['a' => 1], "bad\nkey" . str_repeat('x', 200) => 1]],
            [],
            [],
        );

        $name = (string) $problems[0]['fields'][0];
        self::assertStringNotContainsString("\n", $name);
        self::assertLessThanOrEqual(80, mb_strlen($name));
    }

    /**
     * @param array<mixed> $bulk
     * @param array<mixed> $data
     * @param array<mixed> $cmd
     * @return list<array<string, mixed>>
     */
    private function problemsOf(array $bulk, array $data, array $cmd): array
    {
        try {
            $this->expander->expand($bulk, $data, $cmd);
        } catch (RecordsApplyValidationException $exception) {
            return $exception->getProblems();
        }

        self::fail('Expected a validation exception.');
    }
}
