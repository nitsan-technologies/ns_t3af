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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\Service\CreditOverviewLineService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class CreditOverviewLineServiceTest extends TestCase
{
    public function testMapSummaryToBadgeReturnsUsedTotalLabelAndLevel(): void
    {
        $service = (new ReflectionClass(CreditOverviewLineService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CreditOverviewLineService::class, 'mapSummaryToBadge');

        $badge = $method->invoke(
            $service,
            [
                'remainingFormatted' => '96.19',
                'totalFormatted' => '500',
            ],
            403.81,
            '403.81',
            19,
        );

        self::assertSame('403.81/500 cr', $badge['creditsLabel']);
        self::assertSame('403.81', $badge['usedFormatted']);
        self::assertSame('500', $badge['totalFormatted']);
        self::assertSame('96.19', $badge['remainingFormatted']);
        self::assertSame(19, $badge['percentLeft']);
        self::assertSame('low', $badge['level']);
    }

    public function testResolveLevelThresholds(): void
    {
        $service = (new ReflectionClass(CreditOverviewLineService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CreditOverviewLineService::class, 'resolveLevel');

        self::assertSame('critical', $method->invoke($service, 5));
        self::assertSame('critical', $method->invoke($service, 10));
        self::assertSame('low', $method->invoke($service, 40));
        self::assertSame('healthy', $method->invoke($service, 41));
        self::assertSame('healthy', $method->invoke($service, 100));
    }
}
