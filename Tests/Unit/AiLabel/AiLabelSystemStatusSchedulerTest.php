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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use NITSAN\NsT3AF\AiLabel\Service\AiLabelSystemStatusService;
use NITSAN\NsT3AF\AiLabel\Service\EuIconManifestService;
use PHPUnit\Framework\TestCase;

final class AiLabelSystemStatusSchedulerTest extends TestCase
{
    public function testAuditScheduleReportsNotScheduledWithoutMatchingTask(): void
    {
        $service = new AiLabelSystemStatusService(
            new EuIconManifestService(),
            null,
        );
        $schedule = $service->auditSchedule();

        self::assertFalse($schedule['scheduled']);
        self::assertSame('Not scheduled', $schedule['badge']);
        self::assertSame('warning', $schedule['variant']);
    }

    public function testAuditCommandConstantMatchesCli(): void
    {
        self::assertSame('t3af:ailabel:audit', AiLabelSystemStatusService::AUDIT_COMMAND);
    }
}
