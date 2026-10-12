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

use NITSAN\NsT3AF\Agent\Service\RequestedLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RequestedLanguageTest extends TestCase
{
    private const SITE = [['id' => 0, 'title' => 'English'], ['id' => 1, 'title' => 'German']];

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function requests(): iterable
    {
        yield 'japanese' => ['Save a glossary term: "TYPO3" stays "TYPO3" in Japanese', 'Japanese'];
        yield 'klingon' => ['Save the glossary term Page as Qo\'noS in Klingon', 'Klingon'];
        yield 'french' => ['Translate this page into French', 'French'];
        yield 'german name' => ['Übersetze diese Seite ins Japanische', 'Japanese'];
        yield 'german' => ['Translate this page into German', null];
        yield 'deutsch' => ['Übersetze diese Seite auf Deutsch', null];
        yield 'term in quotes' => ['Save the glossary term "French fries" as "Pommes" in German', null];
        yield 'site language beside other' => ['Translate the French page into German', null];
        yield 'no language' => ['Translate this page', null];
    }

    #[Test]
    #[DataProvider('requests')]
    public function namesTheMissingLanguageOnly(string $request, ?string $expected): void
    {
        self::assertSame($expected, RequestedLanguage::unavailable($request, self::SITE));
    }

    #[Test]
    public function withoutSiteLanguagesNothingIsRefused(): void
    {
        self::assertNull(RequestedLanguage::unavailable('Translate into Japanese', []));
    }

    #[Test]
    public function onlyLanguageToolsAreChecked(): void
    {
        self::assertTrue(RequestedLanguage::isLanguageTool('t3ai_translate_page'));
        self::assertFalse(RequestedLanguage::isLanguageTool('write_table'));
    }
}
