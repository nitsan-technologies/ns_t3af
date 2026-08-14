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

use NITSAN\NsT3AF\Credits\CreditsReceiptEntryType;
use PHPUnit\Framework\TestCase;

final class CreditsReceiptEntryTypeTest extends TestCase
{
    public function testNormalizeDefaultsUnknownToDebit(): void
    {
        self::assertSame(CreditsReceiptEntryType::DEBIT, CreditsReceiptEntryType::normalize(null));
        self::assertSame(CreditsReceiptEntryType::DEBIT, CreditsReceiptEntryType::normalize(''));
        self::assertSame(CreditsReceiptEntryType::CREDIT, CreditsReceiptEntryType::normalize('CREDIT'));
    }

    public function testNormalizeFilterDefaultsToAll(): void
    {
        self::assertSame(CreditsReceiptEntryType::ALL, CreditsReceiptEntryType::normalizeFilter(null));
        self::assertSame(CreditsReceiptEntryType::ALL, CreditsReceiptEntryType::normalizeFilter('bogus'));
        self::assertSame(CreditsReceiptEntryType::DEBIT, CreditsReceiptEntryType::normalizeFilter('debit'));
    }
}
