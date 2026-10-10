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

use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PreferredStoragePidTest extends TestCase
{
    #[Test]
    public function folderBeatsANormalPageWithMoreRows(): void
    {
        $page123 = RecordService::scorePreferredStoragePid(4, 1, '');
        $folder53 = RecordService::scorePreferredStoragePid(3, 254, '');

        self::assertGreaterThan($page123, $folder53);
    }

    #[Test]
    public function newsModuleBeatsAFolderWithMoreRows(): void
    {
        $folder = RecordService::scorePreferredStoragePid(10, 254, '');
        $newsModule = RecordService::scorePreferredStoragePid(1, 1, 'news');

        self::assertGreaterThan($folder, $newsModule);
    }

    #[Test]
    public function onlyNewsModuleOrSysfolderCountAsSuitableStorage(): void
    {
        self::assertTrue(RecordService::isSuitableNewsStorage(254, ''));
        self::assertTrue(RecordService::isSuitableNewsStorage(1, 'news'));
        self::assertFalse(RecordService::isSuitableNewsStorage(1, ''));
        self::assertFalse(RecordService::isSuitableNewsStorage(1, 'web_layout'));
    }
}
