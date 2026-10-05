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
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
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
 * Regression: sys_file_reference placeholders must not contain "_", otherwise
 * DataHandler::processRemapStack() parses them as "<table>_<uid>" and the
 * parent field remap fails (v12: exception after the row is written → MCP
 * retry duplicates; v13/v14: reference silently dropped from the parent).
 *
 * @internal
 */
final class DataHandlerServiceFileReferenceTest extends TestCase
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
    public function createFileReferencesUsesPlaceholdersWithoutUnderscore(): void
    {
        /** @var array<string, array<int|string, array<string, mixed>>> $capturedDatamap */
        $capturedDatamap = [];

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler
            ->expects(self::once())
            ->method('start')
            ->willReturnCallback(static function (array $datamap) use (&$capturedDatamap): void {
                $capturedDatamap = $datamap;
            });
        $dataHandler
            ->expects(self::once())
            ->method('process_datamap')
            ->willReturnCallback(static function () use ($dataHandler, &$capturedDatamap): void {
                $uid = 500;
                foreach (array_keys($capturedDatamap['sys_file_reference'] ?? []) as $newId) {
                    $dataHandler->substNEWwithIDs[(string) $newId] = ++$uid;
                }
            });
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        // v13 BackendUtility::getRecord() early-returns null when $GLOBALS['TCA'][$table]
        // is empty. v14 uses TcaSchemaFactory instead (mocked below).
        $this->originalTca = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['delete' => 'deleted'],
            'columns' => [],
        ];

        // v14: getRecord resolves TcaSchemaFactory twice (own TCA check + DeletedRestriction).
        $schemaFactory = $this->createMock(TcaSchemaFactory::class);
        $schemaFactory->method('has')->willReturn(true);
        $schemaFactory->method('get')->willReturn($this->createMock(TcaSchema::class));
        GeneralUtility::addInstance(TcaSchemaFactory::class, $schemaFactory);
        GeneralUtility::addInstance(TcaSchemaFactory::class, $schemaFactory);

        $parentRecordResult = $this->createMock(Result::class);
        $parentRecordResult->method('fetchAssociative')->willReturn(['uid' => 10, 'pid' => 5]);

        $connection = $this->createMock(Connection::class);
        $connection->method('update')->willReturn(0);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($this->queryBuilderReturning($parentRecordResult));
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        // Consumed once by BackendUtility::getQueryBuilderForTable(), once by
        // DataHandlerService::syncFileFieldCounter().
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);
        GeneralUtility::addInstance(ConnectionPool::class, $connectionPool);

        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findFileReferences')->willReturn([]);

        $service = new DataHandlerService($this->createMock(SiteFinder::class), $recordService);
        $result = $service->createFileReferences('tt_content', 10, 'image', [42, 43]);

        $placeholders = array_map('strval', array_keys($capturedDatamap['sys_file_reference'] ?? []));
        self::assertCount(2, $placeholders);
        self::assertCount(2, array_unique($placeholders));

        foreach ($placeholders as $placeholder) {
            self::assertStringNotContainsString('_', $placeholder);
            self::assertMatchesRegularExpression('/^NEW[0-9a-f]+$/', $placeholder);
        }

        self::assertSame(
            implode(',', $placeholders),
            $capturedDatamap['tt_content'][10]['image'] ?? null,
            'Parent field must reference exactly the generated placeholders',
        );
        self::assertSame([501, 502], $result);
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
