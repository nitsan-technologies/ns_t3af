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

namespace NITSAN\NsT3AF\Tests\Unit\Api;

use NITSAN\NsT3AF\Api\TtsOptions;
use PHPUnit\Framework\TestCase;

final class TtsOptionsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $opts = new TtsOptions();

        self::assertNull($opts->providerIdentifier);
        self::assertNull($opts->modelId);
        self::assertSame('alloy', $opts->voice);
        self::assertSame('mp3', $opts->format);
        self::assertSame(1.0, $opts->speed);
        self::assertNull($opts->extensionKey);
        self::assertNull($opts->featureKey);
        self::assertNull($opts->requestSource);
    }

    public function testNamedArgOverridesDefaults(): void
    {
        $opts = new TtsOptions(voice: 'nova', format: 'opus', speed: 0.75);

        self::assertSame('nova', $opts->voice);
        self::assertSame('opus', $opts->format);
        self::assertSame(0.75, $opts->speed);
    }
}
