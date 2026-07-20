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

use NITSAN\NsT3AF\Credits\CreditsConstants;
use NITSAN\NsT3AF\Credits\Service\CreditsApiBaseUrlResolver;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\ApplicationContext;

final class CreditsApiBaseUrlResolverTest extends TestCase
{
    private ?string $previousEnvironmentValue = null;

    protected function setUp(): void
    {
        $this->previousEnvironmentValue = getenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE) ?: null;
        putenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE);
    }

    protected function tearDown(): void
    {
        if ($this->previousEnvironmentValue === null) {
            putenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE);
        } else {
            putenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE . '=' . $this->previousEnvironmentValue);
        }
    }

    public function testEnvironmentVariableWinsOverDevelopmentContext(): void
    {
        putenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE . '=' . CreditsConstants::LOCAL_DDEV_API_BASE_URL);

        $resolver = new CreditsApiBaseUrlResolver(new ApplicationContext('Development'));

        self::assertSame(CreditsConstants::LOCAL_DDEV_API_BASE_URL, $resolver->resolve());
    }

    public function testDevelopmentContextUsesStagingWithoutEnvironmentOverride(): void
    {
        $resolver = new CreditsApiBaseUrlResolver(new ApplicationContext('Development'));

        self::assertSame(CreditsConstants::STAGING_API_BASE_URL, $resolver->resolve());
    }

    public function testProductionContextUsesProductionHost(): void
    {
        $resolver = new CreditsApiBaseUrlResolver(new ApplicationContext('Production'));

        self::assertSame(CreditsConstants::DEFAULT_API_BASE_URL, $resolver->resolve());
    }

    public function testKnownBuiltInUrlsIncludeShippedDefaults(): void
    {
        $resolver = new CreditsApiBaseUrlResolver(new ApplicationContext('Production'));

        self::assertTrue($resolver->isKnownBuiltInUrl(CreditsConstants::STAGING_API_BASE_URL));
        self::assertTrue($resolver->isKnownBuiltInUrl(CreditsConstants::DEFAULT_API_BASE_URL));
        self::assertTrue($resolver->isKnownBuiltInUrl(CreditsConstants::LOCAL_DDEV_API_BASE_URL));
        self::assertFalse($resolver->isKnownBuiltInUrl('https://credits.example.org'));
    }

    public function testNormalizeStripsTrailingSlash(): void
    {
        $resolver = new CreditsApiBaseUrlResolver(new ApplicationContext('Production'));

        self::assertSame(
            CreditsConstants::DEFAULT_API_BASE_URL,
            $resolver->normalize(CreditsConstants::DEFAULT_API_BASE_URL . '/'),
        );
    }
}
