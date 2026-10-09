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

use NITSAN\NsT3AF\Credits\Service\CreditsApiResponseCache;
use NITSAN\NsT3AF\Credits\Service\CreditsCatalogLanguageResolver;
use PHPUnit\Framework\TestCase;

final class CreditsCatalogLanguageResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    public function testResolveDefaultsToEnglishWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame('en', (new CreditsCatalogLanguageResolver())->resolve());
    }

    public function testResolveMapsGermanFromBeUsersLang(): void
    {
        $GLOBALS['BE_USER'] = (object) [
            'user' => ['lang' => 'de'],
            'uc' => [],
        ];

        self::assertSame('de', (new CreditsCatalogLanguageResolver())->resolve());
    }

    public function testResolvePrefersUserLangOverUc(): void
    {
        $GLOBALS['BE_USER'] = (object) [
            'user' => ['lang' => 'de'],
            'uc' => ['lang' => 'en'],
        ];

        self::assertSame('de', (new CreditsCatalogLanguageResolver())->resolve());
    }

    public function testResolveFallsBackToUcWhenUserLangEmpty(): void
    {
        $GLOBALS['BE_USER'] = (object) [
            'user' => ['lang' => ''],
            'uc' => ['lang' => 'de'],
        ];

        self::assertSame('de', (new CreditsCatalogLanguageResolver())->resolve());
    }

    public function testResolveMapsGermanLocalePrefix(): void
    {
        $GLOBALS['BE_USER'] = (object) ['user' => ['lang' => 'de_DE.UTF-8']];

        self::assertSame('de', (new CreditsCatalogLanguageResolver())->resolve());
    }

    public function testResolveFallsBackToEnglishForUnsupportedCodes(): void
    {
        $GLOBALS['BE_USER'] = (object) ['user' => ['lang' => 'fr']];

        self::assertSame('en', (new CreditsCatalogLanguageResolver())->resolve());
    }

    public function testNormalizeTreatsTypo3DefaultAsEnglish(): void
    {
        self::assertSame('en', CreditsCatalogLanguageResolver::normalize('default'));
        self::assertSame('de', CreditsCatalogLanguageResolver::normalize('DE'));
        self::assertSame('en', CreditsCatalogLanguageResolver::normalize('en'));
        self::assertSame('en', CreditsCatalogLanguageResolver::normalize('fr'));
        self::assertSame('en', CreditsCatalogLanguageResolver::normalize(''));
    }

    public function testCacheScopesIncludeLanguage(): void
    {
        self::assertSame('features_de', CreditsApiResponseCache::scopeFeatures('de'));
        self::assertSame('features_en', CreditsApiResponseCache::scopeFeatures('en'));
        self::assertSame('products_de', CreditsApiResponseCache::scopeProducts('', 'de'));
        self::assertNotSame(
            CreditsApiResponseCache::scopeProducts('https://a.example/', 'en'),
            CreditsApiResponseCache::scopeProducts('https://a.example/', 'de'),
        );
    }
}
