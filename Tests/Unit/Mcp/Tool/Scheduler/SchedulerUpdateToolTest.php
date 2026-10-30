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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Scheduler;

use NITSAN\NsT3AF\Mcp\Tool\Scheduler\SchedulerUpdateTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SchedulerUpdateToolTest extends TestCase
{
    #[Test]
    public function aDescriptionUpdateKeepsTheStoredStartDate(): void
    {
        $existing = [
            'execution_details' => ['start' => 1710000000, 'frequency' => '0 */24 * * *', 'end' => 0],
            'description' => 'T3CS Training (Site 1)',
        ];
        $merged = SchedulerUpdateTool::withExistingSchedule(['description' => 'T3CS Training (Site 1) QA'], $existing);

        self::assertSame('T3CS Training (Site 1) QA', $merged['description']);
        self::assertSame(1710000000, $merged['execution_details']['start']);
        self::assertSame('0 */24 * * *', $merged['execution_details']['frequency']);
    }

    #[Test]
    public function anExplicitScheduleIsNotReplaced(): void
    {
        $merged = SchedulerUpdateTool::withExistingSchedule(
            ['description' => 'New', 'execution_details' => ['start' => 1]],
            ['execution_details' => ['start' => 1710000000]],
        );

        self::assertSame(1, $merged['execution_details']['start']);
    }
}
