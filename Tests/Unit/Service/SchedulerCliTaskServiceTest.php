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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\SchedulerCliCommandCatalogService;
use NITSAN\NsT3AF\Service\SchedulerCliTaskService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class SchedulerCliTaskServiceTest extends TestCase
{
    public function testListTasksReturnsEmptyArrayWhenSchedulerRepositoryUnavailable(): void
    {
        $service = new SchedulerCliTaskService(
            $this->createMock(ConnectionPool::class),
            new SchedulerCliCommandCatalogService($this->createMock(CommandRegistry::class), ProviderTestStubs::emptyMcpToolsCardRegistry()),
        );

        self::assertSame([], $service->listTasks(['status' => 'all']));
    }

    public function testRunCommandReturnsErrorPayloadWhenCommandMissing(): void
    {
        $service = new SchedulerCliTaskService(
            $this->createMock(ConnectionPool::class),
            new SchedulerCliCommandCatalogService($this->createMock(CommandRegistry::class), ProviderTestStubs::emptyMcpToolsCardRegistry()),
        );

        $result = $service->runCommand('t3af:missing:command');

        self::assertSame(0, $result['ok']);
        self::assertNotSame('', $result['error']);
    }
}
