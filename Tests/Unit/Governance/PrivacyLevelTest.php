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

namespace NITSAN\NsT3AF\Tests\Unit\Governance;

use NITSAN\NsT3AF\Governance\PrivacyLevel;
use PHPUnit\Framework\TestCase;

final class PrivacyLevelTest extends TestCase
{
    public function testLabelsDescribeLoggingOnlyNotEgress(): void
    {
        self::assertStringContainsString('logging', strtolower(PrivacyLevel::Standard->label()));
        self::assertStringContainsString('fingerprint', strtolower(PrivacyLevel::Reduced->label()));
        self::assertStringContainsString('logging', strtolower(PrivacyLevel::None->label()));

        foreach (PrivacyLevel::cases() as $case) {
            self::assertStringNotContainsString('egress', strtolower($case->label()));
            self::assertStringNotContainsString('provider payload', strtolower($case->label()));
        }
    }
}
