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

use NITSAN\NsT3AF\AiLabel\Service\AiLabelMediaFolderPreference;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class AiLabelMediaFolderPreferenceTest extends TestCase
{
    /** @var list<array{module: string, data: mixed}> */
    private array $pushes = [];

    public function testGetReturnsEmptyWhenNothingStored(): void
    {
        $beUser = $this->makeBeUser(null);

        self::assertSame('', (new AiLabelMediaFolderPreference())->get($beUser));
        self::assertSame([], $this->pushes);
    }

    public function testSetStoresIdentifierAndGetReadsItBack(): void
    {
        $beUser = $this->makeBeUser(null);
        $service = new AiLabelMediaFolderPreference();
        $service->set($beUser, '/user_upload/');

        self::assertCount(1, $this->pushes);
        self::assertSame(AiLabelMediaFolderPreference::STORAGE_KEY, $this->pushes[0]['module']);
        self::assertSame(['mediaFolder' => '/user_upload/'], $this->pushes[0]['data']);

        $beUser = $this->makeBeUser(['mediaFolder' => '/user_upload/']);
        self::assertSame('/user_upload/', $service->get($beUser));
    }

    public function testSetIsNoOpWhenUnchanged(): void
    {
        $beUser = $this->makeBeUser(['mediaFolder' => '/user_upload/']);
        (new AiLabelMediaFolderPreference())->set($beUser, '/user_upload/');

        self::assertSame([], $this->pushes);
    }

    public function testIsSameIgnoresTrailingSlash(): void
    {
        $service = new AiLabelMediaFolderPreference();

        self::assertTrue($service->isSame('/user_upload/', '/user_upload'));
        self::assertFalse($service->isSame('', '/user_upload/'));
    }

    /**
     * @return BackendUserAuthentication&MockObject
     */
    private function makeBeUser(mixed $storedValue): BackendUserAuthentication
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->method('getModuleData')->willReturn($storedValue);
        $beUser->method('pushModuleData')->willReturnCallback(
            function (string $module, mixed $data): void {
                $this->pushes[] = ['module' => $module, 'data' => $data];
            },
        );

        return $beUser;
    }
}
