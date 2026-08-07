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

namespace NITSAN\NsT3AF\Tests\Unit\Domain\Model;

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use PHPUnit\Framework\TestCase;

final class BrandContextProfileTest extends TestCase
{
    public function testDecodeStringListFromJson(): void
    {
        self::assertSame(
            ['Formal', 'Professional'],
            BrandContextProfile::decodeStringList('["Formal","Professional"]'),
        );
    }

    public function testDecodePersonasFromJson(): void
    {
        $personas = BrandContextProfile::decodeObjectList('[{"name":"CTO","level":"Expert"}]');

        self::assertSame([['name' => 'CTO', 'level' => 'Expert']], $personas);
    }

    public function testFromRowMapsPrimaryFields(): void
    {
        $profile = BrandContextProfile::fromRow([
            'uid' => 5,
            'pid' => 42,
            'brand_name' => 'NITSAN Technologies',
            'industry' => 'Technology',
            'website_url' => 'https://nitsan.io',
            'is_default' => 1,
        ]);

        self::assertSame(5, $profile->uid);
        self::assertSame(42, $profile->pid);
        self::assertSame('NITSAN Technologies', $profile->brandName);
        self::assertTrue($profile->isDefault);
    }
}
