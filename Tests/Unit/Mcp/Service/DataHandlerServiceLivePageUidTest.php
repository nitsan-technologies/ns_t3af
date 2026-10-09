<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class DataHandlerServiceLivePageUidTest extends TestCase
{
    #[Test]
    public function resolveLivePageUidKeepsNonPositiveIds(): void
    {
        $service = new DataHandlerService(
            $this->createMock(SiteFinder::class),
            $this->createMock(RecordService::class),
        );
        $method = new \ReflectionMethod(DataHandlerService::class, 'resolveLivePageUid');

        self::assertSame(0, $method->invoke($service, 0));
        self::assertSame(-1, $method->invoke($service, -1));
    }

    #[Test]
    public function aNewContentElementGetsTheNormalColumnWhenColPosIsMissing(): void
    {
        self::assertSame(
            ['header' => 'About AI', 'colPos' => 0],
            DataHandlerService::withContentColumn('tt_content', ['header' => 'About AI']),
        );
        self::assertSame(
            ['colPos' => 2],
            DataHandlerService::withContentColumn('tt_content', ['colPos' => 2]),
        );
        self::assertSame(
            ['title' => 'Page'],
            DataHandlerService::withContentColumn('pages', ['title' => 'Page']),
        );
    }
}
