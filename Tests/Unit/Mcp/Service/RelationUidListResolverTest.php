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
use NITSAN\NsT3AF\Mcp\Service\RelationUidListResolver;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

/**
 * @internal
 */
final class RelationUidListResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA'] = [
            'pages' => [
                'ctrl' => ['label' => 'title'],
                'columns' => [
                    'title' => [
                        'label' => 'Title',
                        'config' => ['type' => 'input'],
                    ],
                    'categories' => [
                        'label' => 'Categories',
                        'config' => ['type' => 'category'],
                    ],
                    'authors' => [
                        'label' => 'Authors',
                        'config' => [
                            'type' => 'select',
                            'foreign_table' => 'tx_blog_domain_model_author',
                            'MM' => 'tx_blog_post_author_mm',
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function enrichRecordReplacesCategoryAndMmCountersWithUidLists(): void
    {
        $categoryResult = $this->createMock(Result::class);
        $categoryResult->method('fetchAllAssociative')->willReturn([
            ['uid_foreign' => 1739, 'uid_local' => 126],
            ['uid_foreign' => 1739, 'uid_local' => 200],
        ]);

        $authorResult = $this->createMock(Result::class);
        $authorResult->method('fetchAllAssociative')->willReturn([
            ['uid_local' => 1739, 'uid_foreign' => 8],
            ['uid_local' => 1739, 'uid_foreign' => 12],
        ]);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturnCallback(
            function (string $table) use ($categoryResult, $authorResult): QueryBuilder {
                $rows = match ($table) {
                    'sys_category_record_mm' => $categoryResult,
                    'tx_blog_post_author_mm' => $authorResult,
                    default => $this->createMock(Result::class),
                };

                return $this->queryBuilderReturning($rows);
            },
        );

        $resolver = new RelationUidListResolver($pool, new TcaSchemaService());
        $out = $resolver->enrichRecord('pages', [
            'uid' => 1739,
            'title' => 'Post',
            'categories' => 2,
            'authors' => 2,
        ]);

        self::assertSame('126,200', $out['categories']);
        self::assertSame('8,12', $out['authors']);
        self::assertSame('Post', $out['title']);
    }

    #[Test]
    public function enrichRecordLeavesEmptyRelationAsEmptyString(): void
    {
        $empty = $this->createMock(Result::class);
        $empty->method('fetchAllAssociative')->willReturn([]);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($this->queryBuilderReturning($empty));

        $resolver = new RelationUidListResolver($pool, new TcaSchemaService());
        $out = $resolver->enrichRecord('pages', [
            'uid' => 10,
            'categories' => 0,
        ]);

        self::assertSame('', $out['categories']);
    }

    private function queryBuilderReturning(Result $result): QueryBuilder
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('in')->willReturn('in');
        $expr->method('eq')->willReturn('eq');

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('createNamedParameter')->willReturn('?');
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }
}
