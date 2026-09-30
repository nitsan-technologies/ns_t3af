<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
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
        $service = new DataHandlerService($this->createMock(SiteFinder::class));
        $method = new \ReflectionMethod(DataHandlerService::class, 'resolveLivePageUid');

        self::assertSame(0, $method->invoke($service, 0));
        self::assertSame(-1, $method->invoke($service, -1));
    }
}
