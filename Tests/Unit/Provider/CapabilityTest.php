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

namespace NITSAN\NsT3AF\Tests\Unit\Provider;

use NITSAN\NsT3AF\Provider\Capability;
use PHPUnit\Framework\TestCase;

final class CapabilityTest extends TestCase
{
    public function testFromCsvFiltersUnknownAndDeduplicates(): void
    {
        self::assertSame(['chat', 'vision'], Capability::fromCsv('chat, bogus, vision, chat'));
    }

    public function testFromCsvEmptyReturnsEmpty(): void
    {
        self::assertSame([], Capability::fromCsv(''));
        self::assertSame([], Capability::fromCsv('   '));
    }

    public function testToCsvFiltersAndJoins(): void
    {
        self::assertSame('chat,streaming', Capability::toCsv(['chat', 'fake', 'streaming']));
    }

    public function testAllConstantContainsExpectedValues(): void
    {
        self::assertContains('chat', Capability::ALL);
        self::assertContains('completion', Capability::ALL);
        self::assertContains('embeddings', Capability::ALL);
        self::assertContains('vision', Capability::ALL);
        self::assertContains('streaming', Capability::ALL);
        self::assertContains('tool_use', Capability::ALL);
        self::assertContains('tts', Capability::ALL);
        self::assertContains('image_generation', Capability::ALL);
        self::assertCount(8, Capability::ALL);
    }
}
