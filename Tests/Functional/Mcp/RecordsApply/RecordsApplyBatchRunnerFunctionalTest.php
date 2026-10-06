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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
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
    public function aMoveBatchMovesEveryRecordToThePage(): void
    {
        $this->runner->run('content_move_batch', [], ['tt_content' => array_fill_keys([$this->uids[0], $this->uids[1]], ['move' => $this->otherPage])]);

        self::assertSame($this->otherPage, $this->pid($this->uids[0]));
        self::assertSame($this->otherPage, $this->pid($this->uids[1]));
        self::assertSame(self::SITE_ROOT_PAGE_ID, $this->pid($this->uids[2]));
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
}
