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
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Real database: the transaction, the rollback, the ordering and the audit trail of records_apply.
 */
final class RecordsApplyServiceFunctionalTest extends FunctionalTestCase
{
    private const SITE_ROOT_PAGE_ID = 1;

    private const ADMIN_UID = 1;

    private const EDITOR_UID = 2;

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

    private RecordsApplyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);
        $this->setUpBackendUser(self::ADMIN_UID);

        $this->service = $this->get(RecordsApplyService::class);
    }

    #[Test]
    public function aPageAndItsElementsAreCreatedInOneCallInTheOrderSent(): void
    {
        $result = $this->service->apply(
            [
                'pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Batch page']],
                'tt_content' => [
                    'NEWc1' => ['pid' => 'NEWpage', 'CType' => 'text', 'colPos' => 0, 'header' => 'First'],
                    'NEWc2' => ['pid' => 'NEWpage', 'CType' => 'text', 'colPos' => 0, 'header' => 'Second'],
                    'NEWc3' => ['pid' => 'NEWpage', 'CType' => 'text', 'colPos' => 0, 'header' => 'Third'],
                ],
            ],
            [],
            false,
            true,
            true,
        );

        self::assertTrue($result->written);
        self::assertCount(4, $result->created);
        self::assertSame(['create' => 1], $result->operations['pages']);
        self::assertSame(['create' => 3], $result->operations['tt_content']);

        $pageUid = $result->created['NEWpage'];
        self::assertSame(['First', 'Second', 'Third'], $this->headersOnPage($pageUid));
    }

    #[Test]
    public function newElementsAreAppendedAfterTheExistingOnesOfThePage(): void
    {
        /** @var DataHandlerService $dataHandlerService */
        $dataHandlerService = $this->get(DataHandlerService::class);
        $dataHandlerService->createRecord('tt_content', self::SITE_ROOT_PAGE_ID, ['CType' => 'text', 'colPos' => 0, 'header' => 'Existing 1']);
        $dataHandlerService->createRecord('tt_content', self::SITE_ROOT_PAGE_ID, ['CType' => 'text', 'colPos' => 0, 'header' => 'Existing 2']);

        $this->service->apply(
            [
                'tt_content' => [
                    'NEWa' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => 'New A'],
                    'NEWb' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'colPos' => 0, 'header' => 'New B'],
                ],
            ],
            [],
            false,
            true,
            true,
        );

        $headers = $this->headersOnPage(self::SITE_ROOT_PAGE_ID);

        // A plain create puts a record on TOP of its page, so the order of the two existing ones is not
        // the point here: both must still come before the new ones, which keep the order they were sent in.
        self::assertCount(4, $headers);
        self::assertSame(['New A', 'New B'], array_slice($headers, 2));
        self::assertEqualsCanonicalizing(['Existing 1', 'Existing 2'], array_slice($headers, 0, 2));
    }

    #[Test]
    public function aDryRunWritesNothingButReportsWhatWouldHappen(): void
    {
        $result = $this->service->apply(
            ['pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Dry run page']]],
            [],
            true,
            true,
            true,
        );

        self::assertFalse($result->written);
        self::assertTrue($result->dryRun);
        self::assertArrayHasKey('NEWpage', $result->created);
        self::assertSame(0, $this->countWhere('pages', 'title', 'Dry run page'));
    }

    #[Test]
    public function oneRefusedRecordRollsBackTheValidOnesBeforeIt(): void
    {
        try {
            $this->service->apply(
                [
                    'pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Must not survive']],
                    // The preflight cannot foresee this one: be_groups is a root-level table, so DataHandler
                    // refuses it below a page even for an admin. Only DataHandler knows.
                    'be_groups' => ['NEWgroup' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Nowhere']],
                ],
                [],
                false,
                true,
                false,
            );
            self::fail('Expected DataHandler to refuse the root-level record below a page.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('datahandler', $exception->getMessage());
        }

        self::assertSame(0, $this->countWhere('pages', 'title', 'Must not survive'));
        self::assertSame(0, $this->countWhere('be_groups', 'title', 'Nowhere'));
    }

    #[Test]
    public function theAuditEntrySurvivesTheRollbackItDescribes(): void
    {
        try {
            $this->service->apply(
                ['be_groups' => ['NEWgroup' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Nowhere']]],
                [],
                false,
                true,
                false,
            );
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException) {
            // expected
        }

        $rows = $this->getConnectionPool()->getConnectionForTable('sys_log')
            ->executeQuery("SELECT details FROM sys_log WHERE channel = 'nst3af.mcp' AND details LIKE 'MCP records_apply apply failed%'")
            ->fetchFirstColumn();

        self::assertNotSame([], $rows, 'The failed batch left no audit entry in sys_log.');
        self::assertStringNotContainsString('Nowhere', implode(' ', $rows), 'The audit entry must not hold field values.');
    }

    #[Test]
    public function aRecordOnAPageThatDoesNotExistIsRefusedBeforeAnythingIsWritten(): void
    {
        try {
            $this->service->apply(
                [
                    'pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'Must not exist']],
                    'tt_content' => ['NEWc' => ['pid' => 999999, 'CType' => 'text', 'header' => 'Nowhere']],
                ],
                [],
                false,
                true,
                true,
            );
            self::fail('Expected a validation exception.');
        } catch (RecordsApplyValidationException $exception) {
            self::assertStringContainsString('Page 999999 was not found', (string) $exception->getProblems()[0]['error']);
        }

        self::assertSame(0, $this->countWhere('pages', 'title', 'Must not exist'));
        self::assertSame(0, $this->countWhere('tt_content', 'header', 'Nowhere'));
    }

    #[Test]
    public function everyHistoryRowOfACommittedBatchCarriesTheBatchId(): void
    {
        $result = $this->service->apply(
            ['pages' => ['NEWpage' => ['pid' => self::SITE_ROOT_PAGE_ID, 'title' => 'History page']]],
            [],
            false,
            true,
            true,
        );

        $count = (int) $this->getConnectionPool()->getConnectionForTable('sys_history')
            ->executeQuery('SELECT COUNT(*) FROM sys_history WHERE correlation_id LIKE ?', ['%' . $result->batchId . '%'])
            ->fetchOne();

        self::assertGreaterThanOrEqual(1, $count);
    }

    #[Test]
    public function cmdDeletesAnExistingRecordAndLeavesTheOthersAlone(): void
    {
        /** @var DataHandlerService $dataHandlerService */
        $dataHandlerService = $this->get(DataHandlerService::class);
        $keep = $dataHandlerService->createRecord('tt_content', self::SITE_ROOT_PAGE_ID, ['CType' => 'text', 'header' => 'Keep']);
        $remove = $dataHandlerService->createRecord('tt_content', self::SITE_ROOT_PAGE_ID, ['CType' => 'text', 'header' => 'Remove']);

        $result = $this->service->apply([], ['tt_content' => [$remove => ['delete' => 1]]], false, true, true);

        self::assertSame(['delete' => 1], $result->operations['tt_content']);
        self::assertSame(1, $this->countWhere('tt_content', 'header', 'Keep'));
        self::assertSame(0, $this->countDeleted('tt_content', $keep));
        self::assertSame(1, $this->countDeleted('tt_content', $remove));
    }

    #[Test]
    public function aUserWithoutTableRightsIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->setUpBackendUser(self::EDITOR_UID);
        $service = $this->get(RecordsApplyService::class);

        try {
            $service->apply(
                ['tt_content' => ['NEWc' => ['pid' => self::SITE_ROOT_PAGE_ID, 'CType' => 'text', 'header' => 'Blocked']]],
                [],
                false,
                true,
                true,
            );
            self::fail('Expected a validation exception.');
        } catch (RecordsApplyValidationException $exception) {
            self::assertSame('No permission to modify this table.', $exception->getProblems()[0]['error']);
        }

        self::assertSame(0, $this->countWhere('tt_content', 'header', 'Blocked'));
    }

    /**
     * @return list<string>
     */
    private function headersOnPage(int $pageUid): array
    {
        return array_map('strval', $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery('SELECT header FROM tt_content WHERE pid = ? AND deleted = 0 ORDER BY sorting ASC, uid ASC', [$pageUid])
            ->fetchFirstColumn());
    }

    private function countWhere(string $table, string $field, string $value): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $field . ' = ?', [$value])
            ->fetchOne();
    }

    private function countDeleted(string $table, int $uid): int
    {
        return (int) $this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery('SELECT COUNT(*) FROM ' . $table . ' WHERE uid = ? AND deleted = 1', [$uid])
            ->fetchOne();
    }
}
