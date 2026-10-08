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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Search;

use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Search\PagesSearchTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PagesSearchToolTest extends TestCase
{
    #[Test]
    public function aNameMissedOnTheCurrentPageIsFoundInTheRestOfTheTree(): void
    {
        $records = $this->createMock(RecordService::class);
        $pids = [];
        $records->method('search')->willReturnCallback(
            function (
                string $table,
                array $conditions,
                int $limit,
                int $offset,
                array $fields,
                ?int $pid = null,
                ?string $orderBy = null,
                string $orderDirection = 'ASC',
            ) use (&$pids): array {
                $pids[] = $pid;
                if ($pid !== null) {
                    return ['records' => [], 'total' => 0];
                }

                return ['records' => [['uid' => 75, 'title' => 'Sample', 'hidden' => 1]], 'total' => 1];
            },
        );

        $schema = $this->createMock(TcaSchemaService::class);
        $schema->method('getReadFields')->willReturn(['title', 'hidden']);

        $json = (new PagesSearchTool($records, $schema))->execute('Sample', pid: 1);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([1, null], $pids);
        self::assertSame(75, $decoded['records'][0]['uid']);
    }

    #[Test]
    public function aVisibleOnlySearchStillReturnsAHiddenPage(): void
    {
        $records = $this->createMock(RecordService::class);
        $sawHiddenFilter = [];
        $records->method('search')->willReturnCallback(
            function (
                string $table,
                array $conditions,
                int $limit,
                int $offset,
                array $fields,
                ?int $pid = null,
                ?string $orderBy = null,
                string $orderDirection = 'ASC',
            ) use (&$sawHiddenFilter): array {
                $sawHiddenFilter[] = isset($conditions['hidden']);
                if (isset($conditions['hidden'])) {
                    return ['records' => [], 'total' => 0];
                }

                return ['records' => [['uid' => 75, 'title' => 'Sample', 'hidden' => 1]], 'total' => 1];
            },
        );

        $schema = $this->createMock(TcaSchemaService::class);
        $schema->method('getReadFields')->willReturn(['title', 'hidden']);

        $json = (new PagesSearchTool($records, $schema))->execute('{"title":"Sample","hidden":{"op":"eq","value":"0"}}');
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([true, false], $sawHiddenFilter);
        self::assertSame(75, $decoded['records'][0]['uid']);
    }

    #[Test]
    public function anEmptySiteWideSearchIsNotRepeated(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())->method('search')->willReturn(['records' => [], 'total' => 0]);

        $schema = $this->createMock(TcaSchemaService::class);
        $schema->method('getReadFields')->willReturn(['title']);

        (new PagesSearchTool($records, $schema))->execute('Missing');
    }
}
