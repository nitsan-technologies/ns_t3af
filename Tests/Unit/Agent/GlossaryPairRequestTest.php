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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\GlossaryPairRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class GlossaryPairRequestTest extends TestCase
{
    #[Test]
    public function weSayNotNamesTheSourceAndTheTranslation(): void
    {
        self::assertSame(
            ['source' => 'Layout', 'target' => 'Layoutansicht'],
            GlossaryPairRequest::parse('remember that we say Layoutansicht not Layout'),
        );
    }

    #[Test]
    public function anArrowInAGlossaryRequestNamesTheTwoWords(): void
    {
        self::assertSame(
            ['source' => 'Layout', 'target' => 'Layoutansicht'],
            GlossaryPairRequest::parse('Add Layout -> Layoutansicht to the glossary'),
        );
        self::assertSame(
            ['source' => 'Pagetree', 'target' => 'Seitenbaum'],
            GlossaryPairRequest::parse('Add Pagetree → Seitenbaum to the glossary'),
        );
    }

    #[Test]
    public function aNamedLanguageDoesNotHideTheTwoWords(): void
    {
        self::assertSame(
            ['source' => 'Test 123', 'target' => 'Agent 123'],
            GlossaryPairRequest::parse('Remember that in French we say Agent 123 not Test 123.'),
        );
    }

    #[Test]
    public function anOrdinaryRequestIsLeftAlone(): void
    {
        self::assertNull(GlossaryPairRequest::parse('make this page look better in Google'));
        self::assertNull(GlossaryPairRequest::parse('Save a glossary term: "Layout" should be translated as "Layoutansicht".'));
    }
}
