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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyBatchRunner;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyMoveCommandChainer;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsUndoService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Real database: the generated delete/update/move batch tools run all or nothing.
 */
final class RecordsApplyBatchRunnerFunctionalTest extends FunctionalTestCase
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

    private RecordsApplyBatchRunner $runner;

    /** @var list<int> */
    private array $uids = [];

    private int $otherPage = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);
        $this->setUpBackendUser(self::ADMIN_UID);

        $this->runner = $this->get(RecordsApplyBatchRunner::class);

        $setup = $this->get(RecordsApplyService::class)->apply(
            [
                'pages' => ['NEWother' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Other page']],
                'tt_content' => [
                    'NEWa' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => 'A'],
                    'NEWb' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => 'B'],
                    'NEWc' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => 'C'],
                ],
            ],
            [],
            false,
            true,
            true,
        );
        $this->uids = [$setup->created['NEWa'], $setup->created['NEWb'], $setup->created['NEWc']];
        $this->otherPage = $setup->created['NEWother'];
    }

    #[Test]
    public function aDeleteBatchDeletesEveryRecordInOneRun(): void
    {
        $result = $this->runner->run('content_delete_batch', [], ['tt_content' => array_fill_keys($this->uids, ['delete' => 1])]);

        self::assertTrue($result->written);
        self::assertSame(['tt_content' => ['delete' => 3]], $result->operations);
        foreach ($this->uids as $uid) {
            self::assertSame(1, $this->deletedFlag($uid));
        }
    }

    #[Test]
    public function anUpdateBatchChangesEveryRecordOrNone(): void
    {
        $this->runner->run('content_update_batch', ['tt_content' => array_fill_keys($this->uids, ['header' => 'Same'])], []);
        foreach ($this->uids as $uid) {
            self::assertSame('Same', $this->header($uid));
        }

        // One record is gone: the engine refuses the whole batch before it writes anything.
        try {
            $this->runner->run('content_update_batch', ['tt_content' => [$this->uids[0] => ['header' => 'Changed'], 99999 => ['header' => 'Changed']]], []);
            self::fail('Expected the batch to be refused.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500006, $exception->getCode());
        }

        self::assertSame('Same', $this->header($this->uids[0]));
    }

    #[Test]
    public function aMoveBatchMovesEveryRecordToThePageInRequestOrder(): void
    {
        $ordered = [$this->uids[0], $this->uids[1]];
        $this->runner->run(
            'content_move_batch',
            [],
            ['tt_content' => RecordsApplyMoveCommandChainer::chain('tt_content', $ordered, $this->otherPage)],
        );

        self::assertSame($this->otherPage, $this->pid($this->uids[0]));
        self::assertSame($this->otherPage, $this->pid($this->uids[1]));
        self::assertSame(self::SITE_ROOT_PAGE_ID, $this->pid($this->uids[2]));
        self::assertSame(['A', 'B'], $this->headersOnPage($this->otherPage));
    }

    #[Test]
    public function aBulkMoveKeepsRequestOrderAndSurvivesPreflightNegativeTargets(): void
    {
        $service = $this->get(RecordsApplyService::class);
        $result = $service->apply(
            [],
            [],
            false,
            true,
            true,
            [['table' => 'tt_content', 'uids' => $this->uids, 'move' => $this->otherPage]],
        );

        self::assertTrue($result->written);
        self::assertSame(['A', 'B', 'C'], $this->headersOnPage($this->otherPage));
        foreach ($this->uids as $uid) {
            self::assertSame($this->otherPage, $this->pid($uid));
        }
    }

    #[Test]
    public function undoingABulkMoveOfConsecutiveRecordsRestoresSourceOrder(): void
    {
        $service = $this->get(RecordsApplyService::class);
        $batch = $service->apply(
            [],
            [],
            false,
            true,
            true,
            [['table' => 'tt_content', 'uids' => $this->uids, 'move' => $this->otherPage]],
        );

        $outcome = $this->get(RecordsUndoService::class)->undo($batch->batchId, false);

        self::assertTrue($outcome['result']->written);
        self::assertSame(['A', 'B', 'C'], $this->headersAmong($this->uids));
        foreach ($this->uids as $uid) {
            self::assertSame(self::SITE_ROOT_PAGE_ID, $this->pid($uid));
        }
    }

    #[Test]
    public function undoingAMoveBatchOnATableWithoutSortbyRestoresEachRecordToItsOldPid(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['sortby']);

        $service = $this->get(RecordsApplyService::class);
        $batch = $this->runner->run(
            'content_move_batch',
            [],
            ['tt_content' => RecordsApplyMoveCommandChainer::chain('tt_content', $this->uids, $this->otherPage)],
        );

        foreach ($this->uids as $uid) {
            self::assertSame($this->otherPage, $this->pid($uid));
        }

        $undo = $this->get(RecordsUndoService::class);
        $outcome = $undo->undo($batch->batchId, false);

        self::assertTrue($outcome['result']->written);
        foreach ($this->uids as $uid) {
            self::assertSame(self::SITE_ROOT_PAGE_ID, $this->pid($uid));
        }

        try {
            $undo->undo($batch->batchId, false);
            self::fail('Expected the second undo to be refused as already undone.');
        } catch (ToolCallException $exception) {
            self::assertSame(1790500018, $exception->getCode());
        }
    }

    #[Test]
    public function undoingAPartialBulkMovePutsRecordsBackAfterTheUnmovedPredecessor(): void
    {
        $service = $this->get(RecordsApplyService::class);
        $batch = $service->apply(
            [],
            [],
            false,
            true,
            true,
            [['table' => 'tt_content', 'uids' => [$this->uids[1], $this->uids[2]], 'move' => $this->otherPage]],
        );

        self::assertSame(['B', 'C'], $this->headersOnPage($this->otherPage));
        self::assertSame(self::SITE_ROOT_PAGE_ID, $this->pid($this->uids[0]));

        $outcome = $this->get(RecordsUndoService::class)->undo($batch->batchId, false);

        self::assertTrue($outcome['result']->written);
        self::assertSame(['A', 'B', 'C'], $this->headersAmong($this->uids));
    }

    #[Test]
    public function aBulkMoveIncludingARecordAlreadyOnTheTargetKeepsRequestOrder(): void
    {
        $service = $this->get(RecordsApplyService::class);
        $service->apply(
            [],
            [],
            false,
            true,
            true,
            [['table' => 'tt_content', 'uids' => [$this->uids[2]], 'move' => $this->otherPage]],
        );

        $batch = $service->apply(
            [],
            [],
            false,
            true,
            true,
            [['table' => 'tt_content', 'uids' => [$this->uids[0], $this->uids[2], $this->uids[1]], 'move' => $this->otherPage]],
        );

        self::assertTrue($batch->written);
        self::assertSame(['A', 'C', 'B'], $this->headersOnPage($this->otherPage));
    }

    #[Test]
    public function theBatchIsAuditedUnderTheNameOfTheTool(): void
    {
        $this->runner->run('content_delete_batch', [], ['tt_content' => [$this->uids[0] => ['delete' => 1]]]);

        $count = (int) $this->getConnectionPool()->getConnectionForTable('sys_log')
            ->executeQuery("SELECT COUNT(*) FROM sys_log WHERE channel = 'nst3af.mcp' AND details LIKE 'MCP content_delete_batch apply OK%'")
            ->fetchOne();

        self::assertSame(1, $count);
    }

    #[Test]
    public function aBatchToolBatchIdCanBeUndone(): void
    {
        $result = $this->runner->run(
            'content_update_batch',
            ['tt_content' => array_fill_keys($this->uids, ['header' => 'Batched'])],
            [],
        );

        self::assertMatchesRegularExpression('/^ra-[0-9a-f]{20}$/', $result->batchId);
        foreach ($this->uids as $uid) {
            self::assertSame('Batched', $this->header($uid));
        }

        // History is keyed by batchId (correlation scope), not by the audit tool name.
        $historyCount = (int) $this->getConnectionPool()->getConnectionForTable('sys_history')
            ->executeQuery(
                'SELECT COUNT(*) FROM sys_history WHERE correlation_id LIKE ?',
                ['%$' . $result->batchId . ':%'],
            )
            ->fetchOne();
        self::assertGreaterThan(0, $historyCount);

        $outcome = $this->get(RecordsUndoService::class)->undo($result->batchId, false);

        self::assertTrue($outcome['result']->written);
        self::assertSame($result->batchId, $outcome['undoes']);
        self::assertSame('A', $this->header($this->uids[0]));
        self::assertSame('B', $this->header($this->uids[1]));
        self::assertSame('C', $this->header($this->uids[2]));
    }

    private function deletedFlag(int $uid): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery('SELECT deleted FROM tt_content WHERE uid = ?', [$uid])
            ->fetchOne();
    }

    private function header(int $uid): string
    {
        return (string) $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery('SELECT header FROM tt_content WHERE uid = ?', [$uid])
            ->fetchOne();
    }

    private function pid(int $uid): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery('SELECT pid FROM tt_content WHERE uid = ?', [$uid])
            ->fetchOne();
    }

    /** @return list<string> */
    private function headersOnPage(int $pid): array
    {
        $rows = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery(
                'SELECT header FROM tt_content WHERE pid = ? AND deleted = 0 ORDER BY sorting ASC',
                [$pid],
            )
            ->fetchFirstColumn();

        return array_map('strval', $rows);
    }

    /**
     * @param list<int> $uids
     * @return list<string>
     */
    private function headersAmong(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $rows = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery(
                'SELECT header FROM tt_content WHERE uid IN (' . implode(',', array_map('intval', $uids)) . ') AND deleted = 0 ORDER BY sorting ASC',
            )
            ->fetchFirstColumn();

        return array_map('strval', $rows);
    }
}
