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

use NITSAN\NsT3AF\Service\ModuleStateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ModuleStateServiceTest extends TestCase
{
    private ModuleStateService $service;

    /** @var array<int, array{module: string, data: mixed}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        $this->service = new ModuleStateService();
        $this->pushes = [];
    }

    public function testReadReturnsDefaultsWhenNoDataStored(): void
    {
        $beUser = $this->makeBeUser(null);

        $state = $this->service->read($beUser);

        self::assertSame(ModuleStateService::DEFAULTS, $state);
    }

    public function testReadReturnsDefaultsWhenStoredValueIsNotArray(): void
    {
        $beUser = $this->makeBeUser('garbage');

        $state = $this->service->read($beUser);

        self::assertSame(ModuleStateService::DEFAULTS, $state);
    }

    public function testReadMergesStoredArrayOverDefaults(): void
    {
        $beUser = $this->makeBeUser([
            'lastTab' => 'providers',
            'period' => '30d',
            'from' => '',
            'to' => '',
        ]);

        $state = $this->service->read($beUser);

        self::assertSame('providers', $state['lastTab']);
        self::assertSame('30d', $state['period']);
    }

    public function testReadFallsBackToDefaultsForInvalidScalarTypes(): void
    {
        $beUser = $this->makeBeUser([
            'lastTab' => 123,
            'period' => '',
            'from' => null,
            'to' => false,
        ]);

        $state = $this->service->read($beUser);

        self::assertSame(ModuleStateService::DEFAULTS, $state);
    }

    public function testSetLastTabPersistsNewValue(): void
    {
        $beUser = $this->makeBeUser(null);

        $this->service->setLastTab($beUser, 'providers');

        self::assertCount(1, $this->pushes);
        self::assertSame('t3af', $this->pushes[0]['module']);
        self::assertIsArray($this->pushes[0]['data']);
        self::assertSame('providers', $this->pushes[0]['data']['lastTab']);
    }

    public function testSetLastTabIsNoOpWhenValueUnchanged(): void
    {
        $beUser = $this->makeBeUser([
            'lastTab' => 'providers',
            'period' => '7d',
            'from' => '',
            'to' => '',
        ]);

        $this->service->setLastTab($beUser, 'providers');

        self::assertSame([], $this->pushes, 'Push should be skipped when value unchanged.');
    }

    public function testSetLastTabIgnoresEmptyKey(): void
    {
        $beUser = $this->makeBeUser(null);

        $this->service->setLastTab($beUser, '');

        self::assertSame([], $this->pushes);
    }

    public function testSetPeriodPersistsAllThreeFields(): void
    {
        $beUser = $this->makeBeUser(null);

        $this->service->setPeriod($beUser, 'custom', '2026-01-01', '2026-01-31');

        self::assertCount(1, $this->pushes);
        self::assertIsArray($this->pushes[0]['data']);
        self::assertSame('custom', $this->pushes[0]['data']['period']);
        self::assertSame('2026-01-01', $this->pushes[0]['data']['from']);
        self::assertSame('2026-01-31', $this->pushes[0]['data']['to']);
    }

    public function testSetPeriodPreservesLastTab(): void
    {
        $beUser = $this->makeBeUser([
            'lastTab' => 'mcpServer',
            'period' => '7d',
            'from' => '',
            'to' => '',
        ]);

        $this->service->setPeriod($beUser, '30d');

        self::assertCount(1, $this->pushes);
        self::assertIsArray($this->pushes[0]['data']);
        self::assertSame('mcpServer', $this->pushes[0]['data']['lastTab']);
        self::assertSame('30d', $this->pushes[0]['data']['period']);
    }

    public function testSetPeriodIsNoOpWhenAllFieldsUnchanged(): void
    {
        $beUser = $this->makeBeUser([
            'lastTab' => 'dashboard',
            'period' => '14d',
            'from' => '',
            'to' => '',
        ]);

        $this->service->setPeriod($beUser, '14d', '', '');

        self::assertSame([], $this->pushes);
    }

    /**
     * @return BackendUserAuthentication&MockObject
     */
    private function makeBeUser(mixed $storedValue): BackendUserAuthentication
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->method('getModuleData')->willReturn($storedValue);
        $beUser->method('pushModuleData')->willReturnCallback(
            function (string $module, mixed $data) {
                $this->pushes[] = ['module' => $module, 'data' => $data];
            },
        );

        return $beUser;
    }
}
