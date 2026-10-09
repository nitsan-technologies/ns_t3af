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

namespace NITSAN\NsT3AF\Tests\Functional\Mcp\DataHandler;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TC-03: WriteTableTool / DataHandlerService against real schema + ACL denial.
 */
final class WriteTableToolFunctionalTest extends FunctionalTestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        GeneralUtility::makeInstance(Context::class)->setAspect(
            'workspace',
            new WorkspaceAspect(0),
        );
        $this->setUpFrontendRootPage(self::SITE_ROOT_PAGE_ID);
    }

    #[Test]
    public function createAndUpdateContentElementPreservesUntouchedFields(): void
    {
        $this->setUpBackendUser(self::ADMIN_UID);

        /** @var DataHandlerService $dataHandlerService */
        $dataHandlerService = $this->get(DataHandlerService::class);

        $uid = $dataHandlerService->createRecord('tt_content', self::SITE_ROOT_PAGE_ID, [
            'CType' => 'text',
            'header' => 'Original title',
            'colPos' => 1,
            'hidden' => 0,
        ]);

        $dataHandlerService->updateRecord('tt_content', $uid, [
            'header' => 'Updated title',
        ]);

        $row = $this->getConnectionPool()->getConnectionForTable('tt_content')->select(
            ['header', 'colPos', 'hidden'],
            'tt_content',
            ['uid' => $uid],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('Updated title', $row['header']);
        self::assertSame(1, (int) $row['colPos']);
        self::assertSame(0, (int) $row['hidden']);
    }

    #[Test]
    public function createAppendsAfterTheExistingElementsAndLabelsTheRecordAsAi(): void
    {
        $this->setUpBackendUser(self::ADMIN_UID);

        /** @var WriteTableTool $tool */
        $tool = $this->get(WriteTableTool::class);

        $first = json_decode($tool->execute('create', 'tt_content', '{"pid":1,"CType":"text","colPos":0,"header":"First"}'), true);
        $second = json_decode($tool->execute('create', 'tt_content', '{"pid":1,"CType":"text","colPos":0,"header":"Second"}'), true);

        self::assertSame('create', $first['action']);
        self::assertContains('header', $first['fields']);
        self::assertSame(
            ['First', 'Second'],
            array_map('strval', $this->getConnectionPool()->getConnectionForTable('tt_content')
                ->executeQuery('SELECT header FROM tt_content WHERE pid = 1 AND deleted = 0 ORDER BY sorting ASC, uid ASC')
                ->fetchFirstColumn()),
        );

        $label = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['tx_nst3af_ailabel_involvement', 'tx_nst3af_ailabel_recording_source'], 'tt_content', ['uid' => $second['uid']])
            ->fetchAssociative();
        self::assertIsArray($label);
        self::assertSame('ai_generated', $label['tx_nst3af_ailabel_involvement']);
        self::assertSame('mcp', $label['tx_nst3af_ailabel_recording_source']);
    }

    #[Test]
    public function createOnAPageThatDoesNotExistIsRefusedWithAClearMessage(): void
    {
        $this->setUpBackendUser(self::ADMIN_UID);

        /** @var WriteTableTool $tool */
        $tool = $this->get(WriteTableTool::class);
        $result = json_decode($tool->execute('create', 'tt_content', '{"pid":999999,"CType":"text","header":"Nowhere"}'), true);

        self::assertStringContainsString('Page 999999 was not found', $result['error']);
        self::assertSame(
            0,
            (int) $this->getConnectionPool()->getConnectionForTable('tt_content')
                ->executeQuery("SELECT COUNT(*) FROM tt_content WHERE header = 'Nowhere'")
                ->fetchOne(),
        );
    }

    #[Test]
    public function updateAndDeleteGoThroughTheSameEngine(): void
    {
        $this->setUpBackendUser(self::ADMIN_UID);

        /** @var WriteTableTool $tool */
        $tool = $this->get(WriteTableTool::class);
        $created = json_decode($tool->execute('create', 'tt_content', '{"pid":1,"CType":"text","header":"Before"}'), true);
        $uid = (int) $created['uid'];

        $updated = json_decode($tool->execute('update', 'tt_content', '{"header":"After"}', $uid), true);
        self::assertSame(['header'], $updated['fields']);

        $header = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->select(['header'], 'tt_content', ['uid' => $uid])->fetchOne();
        self::assertSame('After', $header);

        $deleted = json_decode($tool->execute('delete', 'tt_content', '{}', $uid), true);
        self::assertSame(['action' => 'delete', 'table' => 'tt_content', 'uid' => $uid], $deleted);

        // Raw SQL on purpose: Connection::select() would hide the deleted row.
        $isDeleted = $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->executeQuery('SELECT deleted FROM tt_content WHERE uid = ?', [$uid])->fetchOne();
        self::assertSame(1, (int) $isDeleted);
    }

    #[Test]
    public function writeTableToolDeniesModifyForUserWithoutTableRights(): void
    {
        $this->setUpBackendUser(self::EDITOR_UID);
        self::assertInstanceOf(BackendUserAuthentication::class, $GLOBALS['BE_USER'] ?? null);
        self::assertFalse($GLOBALS['BE_USER']->check('tables_modify', 'tt_content'));

        /** @var WriteTableTool $tool */
        $tool = $this->get(WriteTableTool::class);
        $result = $tool->execute(
            'update',
            'tt_content',
            '{"header":"Blocked"}',
            1,
        );

        self::assertStringContainsString('Permission denied', $result);
        self::assertStringContainsString('tables_modify', $result);
    }
}
