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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
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
        'extensionmanager',
    ];

    protected array $testExtensionsToLoad = [
        'ns_license',
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
