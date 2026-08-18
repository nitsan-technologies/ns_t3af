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

use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ApplicableTablesResolver;
use NITSAN\NsT3AF\AiLabel\Service\OriginRecorder;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class OriginRecorderApiTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testMarkGeneratedRejectsUnknownTable(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([]);
        $recorder = new OriginRecorder(
            $this->createMock(ConnectionPool::class),
            new ApplicableTablesResolver($dispatcher, new AiLabelSettingsService($extensionSettings)),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tx_not_registered');
        $recorder->markGenerated('tx_not_registered', 1);
    }
}
