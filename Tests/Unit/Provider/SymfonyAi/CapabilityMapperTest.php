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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\SymfonyAi;

use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\SymfonyAi\CapabilityMapper;
use PHPUnit\Framework\TestCase;

final class CapabilityMapperTest extends TestCase
{
    public function testMapsKnownStrings(): void
    {
        $out = (new CapabilityMapper())->map(['input-messages', 'output-streaming', 'tool-calling']);
        self::assertSame([Capability::CHAT, Capability::STREAMING, Capability::TOOL_USE], $out);
    }

    public function testIgnoresUnknown(): void
    {
        $out = (new CapabilityMapper())->map(['some-unknown-cap']);
        self::assertSame([], $out);
    }

    public function testDeduplicatesRepeatedInputMappingToSameTarget(): void
    {
        // input-messages and input-audio both map to chat — must dedupe.
        $out = (new CapabilityMapper())->map(['input-messages', 'input-audio']);
        self::assertSame([Capability::CHAT], $out);
    }

    public function testAcceptsBackedEnums(): void
    {
        $enum = SampleSymfonyCapabilityEnum::InputMessages;
        $out = (new CapabilityMapper())->map([$enum]);
        self::assertSame([Capability::CHAT], $out);
    }

    public function testEmbeddingMapping(): void
    {
        self::assertSame([Capability::EMBEDDINGS], (new CapabilityMapper())->map(['embedding']));
    }
}

enum SampleSymfonyCapabilityEnum: string
{
    case InputMessages = 'input-messages';
    case InputImage = 'input-image';
}
