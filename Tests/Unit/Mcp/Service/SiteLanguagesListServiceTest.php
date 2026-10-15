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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\SiteLanguagesListService;
use NITSAN\NsT3AF\Mcp\Support\SitePageResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * @internal
 */
final class SiteLanguagesListServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aPageThatCannotBeResolvedStillFails(): void
    {
        $pageResolver = $this->createMock(SitePageResolver::class);
        $pageResolver->method('resolve')->willThrowException(
            new \RuntimeException('Page 999 not found.'),
        );

        $service = new SiteLanguagesListService(
            $pageResolver,
            $this->createMock(SiteFinder::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Page 999 not found.');

        $service->listForPage(999, '');
    }

    #[Test]
    public function withoutAPageEachSiteListsItsOwnLanguages(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);
        $user->method('checkLanguageAccess')->willReturn(true);
        $GLOBALS['BE_USER'] = $user;

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([
            'home' => self::site('home', 15, 'Hindi', 'hi-IN'),
            'camino' => self::site('camino', 33, 'Spanish', 'es-ES'),
        ]);
        $pageResolver = $this->createMock(SitePageResolver::class);
        $pageResolver->expects(self::never())->method('resolve');

        $result = (new SiteLanguagesListService($pageResolver, $siteFinder))->listForPage(null, '');

        self::assertArrayNotHasKey('languages', $result);
        self::assertSame(['home', 'camino'], array_column($result['sites'], 'siteIdentifier'));
        self::assertSame('Hindi', $result['sites'][0]['languages'][1]['title']);
        self::assertSame('Spanish', $result['sites'][1]['languages'][1]['title']);
    }

    private static function site(string $identifier, int $rootPageId, string $secondTitle, string $secondLocale): Site
    {
        return new Site($identifier, $rootPageId, [
            'base' => 'https://' . $identifier . '.test/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en-US', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => $secondTitle, 'locale' => $secondLocale, 'base' => '/' . substr($secondLocale, 0, 2) . '/', 'flag' => 'global'],
            ],
        ]);
    }
}
