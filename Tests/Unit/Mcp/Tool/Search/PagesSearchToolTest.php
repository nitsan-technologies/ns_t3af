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
    public function anEmptySiteWideSearchIsNotRepeated(): void
    {
        $records = $this->createMock(RecordService::class);
        $records->expects(self::once())->method('search')->willReturn(['records' => [], 'total' => 0]);

        $schema = $this->createMock(TcaSchemaService::class);
        $schema->method('getReadFields')->willReturn(['title']);

        (new PagesSearchTool($records, $schema))->execute('Missing');
    }
}
