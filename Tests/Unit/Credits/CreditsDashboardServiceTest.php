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

use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Service\CreditsDashboardService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class CreditsDashboardServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousLang = null;

    protected function setUp(): void
    {
        $this->previousLang = $GLOBALS['LANG'] ?? null;
        $GLOBALS['LANG'] = new class {
            public function sL(string $label): string
            {
                return str_contains($label, 'rate_limited') ? 'Wait %s.' : '';
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->previousLang === null) {
            unset($GLOBALS['LANG']);
        } else {
            $GLOBALS['LANG'] = $this->previousLang;
        }
    }

    public function testShouldAbortFurtherFetchesForGlobalFailures(): void
    {
        $service = (new ReflectionClass(CreditsDashboardService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CreditsDashboardService::class, 'shouldAbortFurtherFetches');

        self::assertTrue($method->invoke(
            $service,
            new CreditsApiException('rate_limited', 429, 'rate_limited', ['retry_after' => 60]),
        ));
        self::assertTrue($method->invoke(
            $service,
            new CreditsApiException('network_error', 502),
        ));
        self::assertFalse($method->invoke(
            $service,
            new CreditsApiException('domain_mismatch', 403),
        ));
    }
}
