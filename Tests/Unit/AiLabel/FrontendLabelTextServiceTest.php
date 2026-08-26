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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelTextService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final class FrontendLabelTextServiceTest extends TestCase
{
    public function testResolvesTranslatedLabelFromSiteLanguageOnFrontendRequest(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => $key === 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang.xlf:ailabel.frontend.ai_generated'
                ? 'KI-generiert'
                : $key,
        );

        $siteLanguage = $this->createMock(SiteLanguage::class);
        $siteLanguage->method('getLocale')->willReturn(new Locale('de-DE'));

        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->method('createFromSiteLanguage')->with($siteLanguage)->willReturn($languageService);

        $request = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('language', $siteLanguage);

        $GLOBALS['TYPO3_REQUEST'] = $request;

        try {
            $service = new FrontendLabelTextService(
                $factory,
                new Context(),
                $this->createMock(SiteFinder::class),
            );

            self::assertSame('KI-generiert', $service->forInvolvement(Involvement::AiGenerated));
        } finally {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }

    public function testFallsBackToEnglishWhenTranslationMissing(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);

        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->method('create')->with('default')->willReturn($languageService);

        $service = new FrontendLabelTextService(
            $factory,
            new Context(),
            $this->createMock(SiteFinder::class),
        );

        self::assertSame('AI modified', $service->forInvolvement(Involvement::AiModified));
    }

    public function testUsesDefaultSiteLanguageInBackendContext(): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => $key === 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang.xlf:ailabel.frontend.suggestion'
                ? 'AI suggestion'
                : $key,
        );

        $siteLanguage = $this->createMock(SiteLanguage::class);
        $siteLanguage->method('getLocale')->willReturn(new Locale('en-US'));

        $factory = $this->createMock(LanguageServiceFactory::class);
        $factory->method('createFromSiteLanguage')->with($siteLanguage)->willReturn($languageService);

        $site = $this->createMock(Site::class);
        $site->method('getDefaultLanguage')->willReturn($siteLanguage);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([$site]);

        $request = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $GLOBALS['TYPO3_REQUEST'] = $request;

        try {
            $service = new FrontendLabelTextService(
                $factory,
                new Context(),
                $siteFinder,
            );

            self::assertSame('AI suggestion', $service->forInvolvement(Involvement::Suggestion));
        } finally {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }
}
