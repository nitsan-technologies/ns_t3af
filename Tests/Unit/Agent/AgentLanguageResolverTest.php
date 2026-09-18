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

use NITSAN\NsT3AF\Agent\Service\AgentLanguageResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class AgentLanguageResolverTest extends TestCase
{
    /**
     * @return list<array{string, string, string}>
     */
    public static function backendLanguageProvider(): array
    {
        return [
            ['default', 'en', 'English'],
            ['', 'en', 'English'],
            ['de', 'de', 'German'],
            ['fr', 'fr', 'French'],
            ['pt_BR', 'pt', 'Portuguese'],
            ['zz', 'zz', 'English'],
        ];
    }

    #[Test]
    #[DataProvider('backendLanguageProvider')]
    public function resolvesBackendLanguageFromUserPreference(string $stored, string $expectedIso, string $expectedName): void
    {
        $resolver = new AgentLanguageResolver($this->createMock(SiteFinder::class));

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['lang' => $stored];

        self::assertSame($expectedIso, $resolver->resolveBackendIsoCode($user));
        self::assertSame($expectedName, $resolver->resolveBackendLanguageName($user));
    }

    #[Test]
    public function backendInstructionNamesTheLanguageAndProtectsIdentifiers(): void
    {
        $resolver = new AgentLanguageResolver($this->createMock(SiteFinder::class));

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['lang' => 'de'];

        $instruction = $resolver->backendLanguageInstruction($user);

        self::assertStringContainsString('German', $instruction);
        self::assertStringContainsString('Keep tool names, field keys and ids unchanged.', $instruction);
    }

    #[Test]
    public function contentLanguageComesFromTheTargetSiteLanguage(): void
    {
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getHreflang')->willReturn('de-DE');

        $site = $this->createMock(Site::class);
        $site->method('getLanguageById')->with(2)->willReturn($language);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->with(42)->willReturn($site);

        $resolver = new AgentLanguageResolver($siteFinder);

        self::assertSame('de', $resolver->resolveContentIsoCode(42, 2));
        self::assertSame('German', $resolver->resolveContentLanguageName(42, 2));
        self::assertStringContainsString('German', $resolver->contentLanguageInstruction(42, 2));
    }

    #[Test]
    public function contentLanguageFallsBackToEnglishWithoutASite(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('no site', 1712000000));

        $resolver = new AgentLanguageResolver($siteFinder);

        self::assertSame('en', $resolver->resolveContentIsoCode(42, 0));
        self::assertSame('en', $resolver->resolveContentIsoCode(0));
        self::assertSame('English', $resolver->resolveContentLanguageName(42));
    }
}
