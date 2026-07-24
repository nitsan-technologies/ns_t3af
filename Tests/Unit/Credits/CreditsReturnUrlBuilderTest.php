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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsReturnUrlBuilder;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\SiteFinder;

final class CreditsReturnUrlBuilderTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3Request = null;

    protected function tearDown(): void
    {
        if ($this->previousTypo3Request === null) {
            unset($GLOBALS['TYPO3_REQUEST']);
        } else {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousTypo3Request;
        }
    }

    public function testFromRouteBuildsAbsoluteUrlWithoutBackendToken(): void
    {
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->expects(self::once())
            ->method('buildUriFromRoute')
            ->with(
                't3af_dashboard.providers',
                [],
                UriBuilder::ABSOLUTE_URL,
            )
            ->willReturn(new Uri(
                'https://aiuniverse.ddev.site/typo3/module/t3af/dashboard/providers?token=backend-route-token',
            ));

        $builder = $this->createBuilder($uriBuilder);

        self::assertSame(
            'https://aiuniverse.ddev.site/typo3/module/t3af/dashboard/providers',
            $builder->fromRoute('t3af_dashboard.providers'),
        );
    }

    public function testNormalizeConvertsRelativePathUsingCurrentRequestHost(): void
    {
        $this->previousTypo3Request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = (new \TYPO3\CMS\Core\Http\ServerRequest())
            ->withUri(new Uri('https://aiuniverse.ddev.site/typo3/module/t3af/dashboard/providers?token=abc'));

        $builder = $this->createBuilder($this->createMock(UriBuilder::class));

        self::assertSame(
            'https://aiuniverse.ddev.site/typo3/module/t3af/dashboard/providers',
            $builder->normalize('/typo3/module/t3af/dashboard/providers?token=abc'),
        );
    }

    private function createBuilder(UriBuilder $uriBuilder): CreditsReturnUrlBuilder
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $runtime = new RuntimeSettingsService(
            $this->createMock(RuntimeSettingsRepository::class),
            new CredentialCipher(),
            $this->createMock(ExtensionConfiguration::class),
        );

        return new CreditsReturnUrlBuilder($uriBuilder, new CreditsDomainResolver($siteFinder, $runtime));
    }
}
