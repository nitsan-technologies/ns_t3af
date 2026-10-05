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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Service\MoneyFormatter;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    private MoneyFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MoneyFormatter();
    }

    public function testFormatsKnownSymbolsAndFallsBackToIsoCode(): void
    {
        self::assertSame('$1.50', $this->formatter->format(1.5, 'USD'));
        self::assertSame('€0.0044', $this->formatter->format(0.00435, 'eur'));
        self::assertSame('£2.00', $this->formatter->format(2, 'GBP'));
        self::assertSame('12.50 SEK', $this->formatter->format(12.5, 'sek'));
        self::assertSame('$0.00', $this->formatter->format(0, ''));
    }
}
