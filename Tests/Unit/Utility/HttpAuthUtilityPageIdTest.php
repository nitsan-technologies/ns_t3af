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

namespace NITSAN\NsT3AF\Tests\Unit\Utility;

use NITSAN\NsT3AF\Utility\HttpAuthUtility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use TYPO3\CMS\Core\Http\ServerRequestFactory;

final class HttpAuthUtilityPageIdTest extends TestCase
{
    #[Test]
    public function forPageReturnsSameInstanceWhenPageIdUnchanged(): void
    {
        $utility = $this->makeUtility(45);

        self::assertSame($utility, $utility->forPage(45));
    }

    #[Test]
    public function forPageReturnsCloneWhenPageIdChanges(): void
    {
        $utility = $this->makeUtility(null);

        $scoped = $utility->forPage(45);

        self::assertNotSame($utility, $scoped);
        self::assertSame($scoped, $scoped->forPage(45));
    }

    private function makeUtility(?int $pageId): HttpAuthUtility
    {
        return new HttpAuthUtility(
            'ns_t3af',
            $this->createStub(ClientInterface::class),
            new ServerRequestFactory(),
            $pageId,
        );
    }
}
