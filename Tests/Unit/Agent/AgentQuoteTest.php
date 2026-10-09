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

use NITSAN\NsT3AF\Agent\Service\AgentQuote;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentQuoteTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function englishAndMissingLanguageUseEnglishQuotes(): void
    {
        unset($GLOBALS['LANG']);
        self::assertSame('“Home”', AgentQuote::wrap('Home'));
    }

    #[Test]
    public function germanUsesGermanQuotes(): void
    {
        $GLOBALS['LANG'] = new class {
            public string $lang = 'de';
        };
        self::assertSame('„Start“', AgentQuote::wrap('Start'));
    }
}
