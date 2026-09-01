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

use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class McpRecordPlanServiceTest extends TestCase
{
    #[Test]
    public function planUpdateBuildsBeforeAfterFields(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([42]);
        $recordService->method('findByUid')->willReturn(['description' => 'Old meta']);

        $tcaSchemaService = $this->createMock(TcaSchemaService::class);
        $tcaSchemaService->method('getWritableFields')->willReturn(['description']);

        $service = new McpRecordPlanService($recordService, $tcaSchemaService);
        $plan = $service->planUpdate('pages', 42, ['description' => 'New meta'], 'write_table');

        self::assertSame('update', $plan->action);
        self::assertSame('write_table', $plan->toolName);
        self::assertCount(1, $plan->fields);
        self::assertSame('pages:42:description', $plan->fields[0]->key);
        self::assertSame('Old meta', $plan->fields[0]->currentValue);
        self::assertSame('New meta', $plan->fields[0]->proposedValue);
    }
}
