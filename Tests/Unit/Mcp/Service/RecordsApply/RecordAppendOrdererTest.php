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

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\LastRecordLocator;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordAppendOrderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordAppendOrdererTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    private LastRecordLocator&MockObject $locator;

    private RecordAppendOrderer $orderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['sortby' => 'sorting', 'languageField' => 'sys_language_uid'],
            'columns' => ['colPos' => ['config' => ['type' => 'select']], 'header' => ['config' => ['type' => 'input']]],
        ];
        $GLOBALS['TCA']['tx_plain'] = ['ctrl' => ['label' => 'title'], 'columns' => []];
        $GLOBALS['TCA']['tx_child'] = ['ctrl' => ['sortby' => 'sorting', 'hideTable' => true], 'columns' => []];

        $this->locator = $this->createMock(LastRecordLocator::class);
        $this->orderer = new RecordAppendOrderer($this->locator);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        parent::tearDown();
    }

    #[Test]
    public function theFirstNewRecordGoesAfterTheLastExistingOneAndTheRestChain(): void
    {
        $this->locator->expects(self::once())
            ->method('lastUid')
            ->with('tt_content', 12, ['pid' => 12, 'colPos' => 0, 'header' => 'A'])
            ->willReturn(99);

        $result = $this->orderer->apply([
            'tt_content' => [
                'NEWa' => ['pid' => 12, 'colPos' => 0, 'header' => 'A'],
                'NEWb' => ['pid' => 12, 'colPos' => 0, 'header' => 'B'],
                'NEWc' => ['pid' => 12, 'colPos' => 0, 'header' => 'C'],
            ],
        ]);

        self::assertSame('-99', $result['tt_content']['NEWa']['pid']);
        self::assertSame('-NEWa', $result['tt_content']['NEWb']['pid']);
        self::assertSame('-NEWb', $result['tt_content']['NEWc']['pid']);
        self::assertSame('B', $result['tt_content']['NEWb']['header'], 'Other fields are untouched.');
    }

    #[Test]
    public function anEmptyPageKeepsThePositivePidForTheFirstRecord(): void
    {
        $this->locator->method('lastUid')->willReturn(null);

        $result = $this->orderer->apply([
            'tt_content' => [
                'NEWa' => ['pid' => 12, 'header' => 'A'],
                'NEWb' => ['pid' => 12, 'header' => 'B'],
            ],
        ]);

        self::assertSame(12, $result['tt_content']['NEWa']['pid']);
        self::assertSame('-NEWa', $result['tt_content']['NEWb']['pid']);
    }

    #[Test]
    public function anExplicitNegativePidIsTheClientsOwnPlacement(): void
    {
        $this->locator->expects(self::never())->method('lastUid');

        $datamap = ['tt_content' => ['NEWa' => ['pid' => -5, 'header' => 'A']]];

        self::assertSame($datamap, $this->orderer->apply($datamap));
    }

    #[Test]
    public function differentColumnsAndLanguagesAreOrderedIndependently(): void
    {
        $this->locator->expects(self::exactly(2))
            ->method('lastUid')
            ->willReturnCallback(static fn(string $table, int $pid, array $fields): int => (int) $fields['colPos'] === 0 ? 10 : 20);

        $result = $this->orderer->apply([
            'tt_content' => [
                'NEWa' => ['pid' => 12, 'colPos' => 0],
                'NEWb' => ['pid' => 12, 'colPos' => 1],
                'NEWc' => ['pid' => 12, 'colPos' => 0],
            ],
        ]);

        self::assertSame('-10', $result['tt_content']['NEWa']['pid']);
        self::assertSame('-20', $result['tt_content']['NEWb']['pid']);
        self::assertSame('-NEWa', $result['tt_content']['NEWc']['pid']);
    }

    #[Test]
    public function recordsOnAPageCreatedInTheSameCallAreChainedWithoutALookup(): void
    {
        $this->locator->expects(self::never())->method('lastUid');

        $result = $this->orderer->apply([
            'tt_content' => [
                'NEWa' => ['pid' => 'NEWpage', 'header' => 'A'],
                'NEWb' => ['pid' => 'NEWpage', 'header' => 'B'],
            ],
        ]);

        self::assertSame('NEWpage', $result['tt_content']['NEWa']['pid']);
        self::assertSame('-NEWa', $result['tt_content']['NEWb']['pid']);
    }

    #[Test]
    public function tablesWithoutAManualSortOrderAndInlineChildTablesAreNotTouched(): void
    {
        $this->locator->expects(self::never())->method('lastUid');

        $datamap = [
            'tx_plain' => ['NEWa' => ['pid' => 5], 'NEWb' => ['pid' => 5]],
            'tx_child' => ['NEWc' => ['pid' => 5], 'NEWd' => ['pid' => 5]],
        ];

        self::assertSame($datamap, $this->orderer->apply($datamap));
    }

    #[Test]
    public function existingRecordsAreNotTouched(): void
    {
        $this->locator->expects(self::never())->method('lastUid');

        $datamap = ['tt_content' => [42 => ['header' => 'Changed']]];

        self::assertSame($datamap, $this->orderer->apply($datamap));
    }
}
