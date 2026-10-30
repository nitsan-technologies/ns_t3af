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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use Doctrine\DBAL\Result;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpSiteRequestBootstrap;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Agent Apply sends only the fields on the card. A scheduler description write still
 * keeps the stored start date because DataHandlerService merges it before DataHandler.
 *
 * @internal
 */
final class DataHandlerServiceSchedulerUpdateTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalTca = null;

    protected function tearDown(): void
    {
        if ($this->originalTca !== null) {
            $GLOBALS['TCA'] = $this->originalTca;
            $this->originalTca = null;
        } else {
            unset($GLOBALS['TCA']);
        }
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function aDescriptionOnlySchedulerWriteKeepsTheStoredStartDate(): void
    {
        /** @var array<string, mixed> $capturedDatamap */
        $capturedDatamap = [];

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->errorLog = [];
        $dataHandler
            ->expects(self::once())
            ->method('start')
            ->willReturnCallback(static function (array $datamap) use (&$capturedDatamap): void {
                $capturedDatamap = $datamap;
            });
        $dataHandler->expects(self::once())->method('process_datamap');
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->originalTca = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA']['tx_scheduler_task'] = [
            'ctrl' => ['delete' => 'deleted'],
            'columns' => [],
        ];

        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->method('has')->willReturn(true);
        $schemaFactory->method('get')->willReturn($this->createMock(TcaSchema::class));
        GeneralUtility::addInstance(TcaSchemaFactory::class, $schemaFactory);
        GeneralUtility::addInstance(TcaSchemaFactory::class, $schemaFactory);

        $recordResult = $this->createMock(Result::class);
        $recordResult->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'description' => 'T3CS Training (Site 1)',
            'execution_details' => '{"start":1793277266,"end":0,"cronCmd":"0 */1 * * *"}',
        ]);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($this->queryBuilderReturning($recordResult));
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())->method('assertRecordExists')->with('tx_scheduler_task', 1);

        $service = new DataHandlerService(
            new McpSiteRequestBootstrap($this->createMock(SiteFinder::class)),
            $recordService,
        );
        $service->updateRecord('tx_scheduler_task', 1, ['description' => 'T3CS Training (Site 1) QA']);

        $fields = $capturedDatamap['tx_scheduler_task'][1] ?? [];
        self::assertSame('T3CS Training (Site 1) QA', $fields['description'] ?? null);
        self::assertSame(1793277266, $fields['execution_details']['start'] ?? null);
        self::assertSame('0 */1 * * *', $fields['execution_details']['cronCmd'] ?? null);
    }

    private function queryBuilderReturning(Result $result): QueryBuilder
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('eq');

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('createNamedParameter')->willReturn('?');
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }
}
