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

namespace NITSAN\NsT3AF\Tests\Functional\Mcp\RecordsApply;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsUndoService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Real database: a whole records_apply batch is taken back from its sys_history rows.
 */
final class RecordsUndoFunctionalTest extends FunctionalTestCase
{
    private const SITE_ROOT_PAGE_ID = 1;

    private const ADMIN_UID = 1;

    protected array $coreExtensionsToLoad = [
        'frontend',
        'workspaces',
        'scheduler',
    ];

    protected array $testExtensionsToLoad = [
        'ns_t3af',
    ];

    /**
     * @var array<string, non-empty-string>
     */
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/ns_t3af/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    private RecordsApplyService $apply;

    private RecordsUndoService $undo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);
        $this->setUpBackendUser(self::ADMIN_UID);

        $this->apply = $this->get(RecordsApplyService::class);
        $this->undo = $this->get(RecordsUndoService::class);
    }

    #[Test]
    public function aBatchThatCreatedAPageAndItsElementsIsTakenBackCompletely(): void
    {
        $batch = $this->apply->apply(
            [
                'pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Undo me']],
                'tt_content' => [
                    'NEWc1' => ['pid' => 'NEWpage', 'CType' => 'text', 'colPos' => 0, 'header' => 'One'],
                    'NEWc2' => ['pid' => 'NEWpage', 'CType' => 'text', 'colPos' => 0, 'header' => 'Two'],
                ],
            ],
            [],
            false,
            true,
            true,
        );

        $outcome = $this->undo->undo($batch->batchId, false);

        self::assertTrue($outcome['result']->written);
        self::assertSame($batch->batchId, $outcome['undoes']);
        self::assertSame(RecordsUndoService::undoBatchId($batch->batchId), $outcome['result']->batchId);
        self::assertSame(1, $this->deletedFlag('pages', $batch->created['NEWpage']));
        self::assertSame(1, $this->deletedFlag('tt_content', $batch->created['NEWc1']));
        self::assertSame(1, $this->deletedFlag('tt_content', $batch->created['NEWc2']));
    }

    #[Test]
    public function aDryRunChangesNothingAndTheRealRunStillWorksAfterIt(): void
    {
        $batch = $this->createElements(['Keep me']);
        $uid = $batch->created['NEWc0'];

        $dry = $this->undo->undo($batch->batchId, true);
        self::assertFalse($dry['result']->written);
        self::assertSame(0, $this->deletedFlag('tt_content', $uid));

        $this->undo->undo($batch->batchId, false);
        self::assertSame(1, $this->deletedFlag('tt_content', $uid));
    }

    #[Test]
    public function changedFieldsGetTheirOldValuesBack(): void
    {
        $uid = $this->createElements(['Original'])->created['NEWc0'];

        $change = $this->apply->apply(['tt_content' => [$uid => ['header' => 'Changed', 'subheader' => 'Added']]], [], false, true, true);
        self::assertSame('Changed', $this->field('tt_content', $uid, 'header'));

        $this->undo->undo($change->batchId, false);

        self::assertSame('Original', $this->field('tt_content', $uid, 'header'));
        self::assertSame('', $this->field('tt_content', $uid, 'subheader'));
        self::assertSame(0, $this->deletedFlag('tt_content', $uid));
    }

    #[Test]
    public function deletedRecordsAreRestoredAPageBeforeItsElements(): void
    {
        $created = $this->apply->apply(
            [
                'pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Delete me']],
                'tt_content' => ['NEWc' => ['pid' => 'NEWpage', 'CType' => 'text', 'colPos' => 0, 'header' => 'On the page']],
            ],
            [],
            false,
            true,
            true,
        );
        $pageUid = $created->created['NEWpage'];
        $contentUid = $created->created['NEWc'];

        $deletion = $this->apply->apply([], ['pages' => [$pageUid => ['delete' => 1]]], false, true, true);
        self::assertSame(1, $this->deletedFlag('pages', $pageUid));
        self::assertSame(1, $this->deletedFlag('tt_content', $contentUid));

        $this->undo->undo($deletion->batchId, false);

        self::assertSame(0, $this->deletedFlag('pages', $pageUid));
        self::assertSame(0, $this->deletedFlag('tt_content', $contentUid));
    }

    #[Test]
    public function aMovedRecordGoesBackToItsPage(): void
    {
        $setup = $this->apply->apply(
            [
                'pages' => ['NEWother' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Other page']],
                'tt_content' => [
                    'NEWa' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => 'A'],
                    'NEWb' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => 'B'],
                ],
            ],
            [],
            false,
            true,
            true,
        );
        $otherPage = $setup->created['NEWother'];
        $b = $setup->created['NEWb'];

        $move = $this->apply->apply([], ['tt_content' => [$b => ['move' => $otherPage]]], false, true, true);
        self::assertSame($otherPage, (int) $this->field('tt_content', $b, 'pid'));

        $this->undo->undo($move->batchId, false);

        self::assertSame(self::SITE_ROOT_PAGE_ID, (int) $this->field('tt_content', $b, 'pid'));
    }

    #[Test]
    public function aBatchIsRefusedWhenARecordWasChangedAfterIt(): void
    {
        $batch = $this->createElements(['Touched later']);
        $uid = $batch->created['NEWc0'];
        $this->apply->apply(['tt_content' => [$uid => ['header' => 'Edited by somebody']]], [], false, true, true);

        try {
            $this->undo->undo($batch->batchId, false);
            self::fail('Expected the undo to be refused.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500022, $exception->getCode());
            self::assertStringContainsString('tt_content:' . $uid, $exception->getMessage());
        }

        self::assertSame('Edited by somebody', $this->field('tt_content', $uid, 'header'));
        self::assertSame(0, $this->deletedFlag('tt_content', $uid));
    }

    #[Test]
    public function aBatchCannotBeUndoneTwice(): void
    {
        $batch = $this->createElements(['Once']);
        $this->undo->undo($batch->batchId, false);

        try {
            $this->undo->undo($batch->batchId, false);
            self::fail('Expected the second undo to be refused.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500018, $exception->getCode());
        }
    }

    #[Test]
    public function anUndoCanBeUndoneInTurn(): void
    {
        $batch = $this->createElements(['Back and forth']);
        $uid = $batch->created['NEWc0'];

        $undo = $this->undo->undo($batch->batchId, false);
        self::assertSame(1, $this->deletedFlag('tt_content', $uid));

        $this->undo->undo($undo['result']->batchId, false);

        self::assertSame(0, $this->deletedFlag('tt_content', $uid));
    }

    #[Test]
    public function anUnknownBatchIsRefused(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500019);

        $this->undo->undo('ra-00000000000000000000', false);
    }

    #[Test]
    public function aMalformedBatchIdIsRefused(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500017);

        $this->undo->undo("ra-%'; DROP TABLE pages;--", false);
    }

    #[Test]
    public function aPageThatNowHoldsOtherRecordsIsNotDeleted(): void
    {
        $batch = $this->apply->apply(['pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Shared later']]], [], false, true, true);
        $pageUid = $batch->created['NEWpage'];
        $this->apply->apply(['tt_content' => ['NEWc' => ['pid' => $pageUid, 'CType' => 'text', 'colPos' => 0, 'header' => 'Somebody elses']]], [], false, true, true);

        try {
            $this->undo->undo($batch->batchId, false);
            self::fail('Expected the undo to be refused.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500024, $exception->getCode());
        }

        self::assertSame(0, $this->deletedFlag('pages', $pageUid));
    }

    #[Test]
    public function aFailedUndoWritesNothing(): void
    {
        $batch = $this->createElements(['A', 'B']);
        // Both records exist, but one of them is gone for good: the engine refuses the inverse as a whole.
        $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeStatement('DELETE FROM tt_content WHERE uid = ?', [$batch->created['NEWc1']]);

        try {
            $this->undo->undo($batch->batchId, false);
            self::fail('Expected the undo to be refused.');
        } catch (\Throwable) {
            // expected: the preflight does not find the record any more
        }

        self::assertSame(0, $this->deletedFlag('tt_content', $batch->created['NEWc0']));
    }

    /**
     * @param list<string> $headers
     */
    private function createElements(array $headers): RecordsApplyResult
    {
        $records = [];
        foreach ($headers as $index => $header) {
            $records['NEWc' . $index] = ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => $header];
        }

        return $this->apply->apply(['tt_content' => $records], [], false, true, true);
    }

    private function deletedFlag(string $table, int $uid): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery('SELECT deleted FROM ' . $table . ' WHERE uid = ?', [$uid])
            ->fetchOne();
    }

    private function field(string $table, int $uid, string $field): string
    {
        return (string) $this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery('SELECT ' . $field . ' FROM ' . $table . ' WHERE uid = ?', [$uid])
            ->fetchOne();
    }
}
