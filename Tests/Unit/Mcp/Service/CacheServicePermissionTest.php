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

use NITSAN\NsT3AF\Mcp\Service\CacheService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Editors may not flush all caches of the installation (ticket 14zervyucgk).
 *
 * @internal
 */
final class CacheServicePermissionTest extends TestCase
{
    private mixed $previousUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->previousUser;
        parent::tearDown();
    }

    #[Test]
    public function adminMayClearEverything(): void
    {
        $GLOBALS['BE_USER'] = $this->user(true, []);

        self::assertNull($this->service()->denialReason('all'));
        self::assertNull($this->service()->denialReason('pages'));
    }

    #[Test]
    public function editorWithoutTsConfigMayNotClearAll(): void
    {
        $GLOBALS['BE_USER'] = $this->user(false, []);

        self::assertNotNull($this->service()->denialReason('all'));
        self::assertNull($this->service()->denialReason('pages'));
    }

    #[Test]
    public function editorWithClearCacheAllMayClearAll(): void
    {
        $GLOBALS['BE_USER'] = $this->user(false, ['options.' => ['clearCache.' => ['all' => '1']]]);

        self::assertNull($this->service()->denialReason('all'));
    }

    #[Test]
    public function editorWithPagesSwitchedOffMayNotClearPageCaches(): void
    {
        $GLOBALS['BE_USER'] = $this->user(false, ['options.' => ['clearCache.' => ['pages' => '0']]]);

        self::assertNotNull($this->service()->denialReason('pages'));
    }

    #[Test]
    public function withoutBackendUserNothingMayBeCleared(): void
    {
        $GLOBALS['BE_USER'] = null;

        self::assertNotNull($this->service()->denialReason('pages'));
    }

    private function service(): CacheService
    {
        return new CacheService($this->createMock(CacheManager::class));
    }

    /**
     * @param array<string, mixed> $tsConfig
     */
    private function user(bool $admin, array $tsConfig): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('getTSConfig')->willReturn($tsConfig);

        return $user;
    }
}
