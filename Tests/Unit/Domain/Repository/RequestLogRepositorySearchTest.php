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

namespace NITSAN\NsT3AF\Tests\Unit\Domain\Repository;

use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class RequestLogRepositorySearchTest extends TestCase
{
    public function testUsageSearchLowersQuotedColumns(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => '`' . $identifier . '`',
        );

        $captured = '';
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn(new ExpressionBuilder($connection));
        $queryBuilder->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => '`' . $identifier . '`',
        );
        $queryBuilder->method('createNamedParameter')->willReturn(':search');
        $queryBuilder->method('andWhere')->willReturnCallback(
            function (mixed $predicate) use (&$captured, $queryBuilder): QueryBuilder {
                $captured = (string) $predicate;

                return $queryBuilder;
            },
        );

        $repository = new RequestLogRepository($this->createMock(ConnectionPool::class));
        $method = new \ReflectionMethod(RequestLogRepository::class, 'applyUsageListFilters');
        $method->invoke($repository, $queryBuilder, ['search' => 'Mistral']);

        self::assertStringContainsString('LOWER(`extension_key`) LIKE :search', $captured);
        self::assertStringContainsString('LOWER(`provider_identifier`) LIKE :search', $captured);
        self::assertStringContainsString('LOWER(`raw_meta`) LIKE :search', $captured);
        self::assertStringNotContainsString('`LOWER(extension_key)`', $captured);
        self::assertStringNotContainsString('`LOWER(provider_identifier)`', $captured);
    }
}
