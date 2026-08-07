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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Service\BrandContextLineage;
use PHPUnit\Framework\TestCase;

final class BrandContextLineageTest extends TestCase
{
    public function testProfileUidFromOptionsReadsPositiveInt(): void
    {
        $options = new AiOptions(extra: [BrandContextLineage::EXTRA_PROFILE_UID => 42]);

        self::assertSame(42, BrandContextLineage::profileUidFromOptions($options));
    }

    public function testProfileUidFromOptionsIgnoresInvalidValues(): void
    {
        self::assertNull(BrandContextLineage::profileUidFromOptions(new AiOptions()));
        self::assertNull(BrandContextLineage::profileUidFromOptions(new AiOptions(extra: [
            BrandContextLineage::EXTRA_PROFILE_UID => 0,
        ])));
        self::assertNull(BrandContextLineage::profileUidFromOptions(new AiOptions(extra: [
            BrandContextLineage::EXTRA_PROFILE_UID => 'abc',
        ])));
    }

    public function testStampExtraSetsUid(): void
    {
        $extra = BrandContextLineage::stampExtra(['foo' => 'bar'], 5);

        self::assertSame(5, $extra[BrandContextLineage::EXTRA_PROFILE_UID]);
        self::assertSame('bar', $extra['foo']);
    }
}
