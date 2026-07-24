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

use NITSAN\NsT3AF\Credits\Contract\LicenseDataRepositoryInterface;
use NITSAN\NsT3AF\Credits\Service\LicenseKeyResolver;
use PHPUnit\Framework\TestCase;

final class LicenseKeyResolverTest extends TestCase
{
    public function testBuildLicenseKeysCommaSeparatedReturnsEmptyWithoutRepository(): void
    {
        $resolver = new LicenseKeyResolver(null);

        self::assertSame('', $resolver->buildLicenseKeysCommaSeparated());
    }

    public function testBuildLicenseKeysCommaSeparatedIncludesAllLicensedExtensions(): void
    {
        $repository = $this->createMock(LicenseDataRepositoryInterface::class);
        $repository->method('fetchAllData')->willReturn([
            ['license_key' => 'KEY-A', 'is_life_time' => 1, 'expiration_date' => 0, 'extension_key' => 'ns_t3af'],
            ['license_key' => 'KEY-B', 'is_life_time' => 1, 'expiration_date' => 0, 'extension_key' => 'ns_t3ai'],
            ['license_key' => 'KEY-C', 'is_life_time' => 1, 'expiration_date' => 0, 'extension_key' => 'ns_news_comments'],
        ]);

        $resolver = new LicenseKeyResolver($repository);

        self::assertSame('KEY-A,KEY-B,KEY-C', $resolver->buildLicenseKeysCommaSeparated());
    }

    public function testBuildLicenseKeysCommaSeparatedDedupesSharedKeyAcrossExtensions(): void
    {
        $repository = $this->createMock(LicenseDataRepositoryInterface::class);
        $repository->method('fetchAllData')->willReturn([
            ['license_key' => 'KEY-B', 'is_life_time' => 1, 'expiration_date' => 0, 'extension_key' => 'ns_t3ai'],
            ['license_key' => 'KEY-A', 'is_life_time' => 1, 'expiration_date' => 0, 'extension_key' => 'ns_t3af'],
            ['license_key' => 'KEY-B', 'is_life_time' => 1, 'expiration_date' => 0, 'extension_key' => 'ns_news_comments'],
        ]);

        $resolver = new LicenseKeyResolver($repository);

        self::assertSame('KEY-A,KEY-B', $resolver->buildLicenseKeysCommaSeparated());
    }

    public function testBuildNewLicenseKeysCommaSeparatedReturnsOnlyMissingKeys(): void
    {
        $resolver = new LicenseKeyResolver(null);

        self::assertSame('KEY-B', $resolver->buildNewLicenseKeysCommaSeparated('KEY-A,KEY-B', 'KEY-A'));
        self::assertSame('', $resolver->buildNewLicenseKeysCommaSeparated('KEY-A', 'KEY-A'));
        self::assertSame('KEY-C', $resolver->buildNewLicenseKeysCommaSeparated('KEY-A,KEY-C', 'KEY-A,KEY-B'));
    }

    public function testParseLicenseKeySetTrimsAndDedupes(): void
    {
        $resolver = new LicenseKeyResolver(null);

        self::assertSame(['KEY-A', 'KEY-B'], $resolver->parseLicenseKeySet(' KEY-A , KEY-B , KEY-A '));
        self::assertSame([], $resolver->parseLicenseKeySet(''));
    }
}
