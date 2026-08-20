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

use NITSAN\NsT3AF\Credits\Contract\LicenseDataRepositoryInterface;
use NITSAN\NsT3AF\Credits\Service\LicenseContactResolver;
use PHPUnit\Framework\TestCase;

final class LicenseContactResolverTest extends TestCase
{
    public function testResolveReturnsEmptyWithoutRepository(): void
    {
        $resolver = new LicenseContactResolver(null);

        self::assertSame([], $resolver->resolve());
        self::assertFalse($resolver->hasContact());
    }

    public function testResolvePrefersNsT3afLicense(): void
    {
        $repository = $this->createMock(LicenseDataRepositoryInterface::class);
        $repository->method('fetchData')->willReturnMap([
            ['ns_t3af', [
                [
                    'license_key' => 'T3AF-1',
                    'is_life_time' => 1,
                    'expiration_date' => 0,
                    'name' => 'T3AF User',
                    'email' => 't3af@example.com',
                ],
            ]],
            ['ns_t3ai', [
                [
                    'license_key' => 'AI-1',
                    'is_life_time' => 1,
                    'expiration_date' => 0,
                    'name' => 'AI User',
                    'email' => 'ai@example.com',
                ],
            ]],
            ['ns_t3aa', []],
            ['ns_t3cs', []],
            ['ns_t3as', []],
            ['ns_t3ac', []],
        ]);

        $resolver = new LicenseContactResolver($repository);

        self::assertSame([
            'name' => 'T3AF User',
            'email' => 't3af@example.com',
        ], $resolver->resolve());
    }

    public function testResolveFallsBackToFirstAiUniverseExtension(): void
    {
        $repository = $this->createMock(LicenseDataRepositoryInterface::class);
        $repository->method('fetchData')->willReturnMap([
            ['ns_t3af', []],
            ['ns_t3ai', []],
            ['ns_t3aa', [
                [
                    'license_key' => 'AA-1',
                    'is_life_time' => 1,
                    'expiration_date' => 0,
                    'name' => 'AA User',
                    'email' => 'aa@example.com',
                ],
            ]],
            ['ns_t3cs', []],
            ['ns_t3as', []],
            ['ns_t3ac', []],
        ]);

        $resolver = new LicenseContactResolver($repository);

        self::assertSame([
            'name' => 'AA User',
            'email' => 'aa@example.com',
        ], $resolver->resolve());
    }

    public function testResolveAllowsPartialContactData(): void
    {
        $repository = $this->createMock(LicenseDataRepositoryInterface::class);
        $repository->method('fetchData')->willReturnMap([
            ['ns_t3af', [
                [
                    'license_key' => 'T3AF-1',
                    'is_life_time' => 1,
                    'expiration_date' => 0,
                    'name' => '',
                    'email' => 'partial@example.com',
                ],
            ]],
        ]);

        $resolver = new LicenseContactResolver($repository);

        self::assertSame(['email' => 'partial@example.com'], $resolver->resolve());
    }

    public function testResolveSkipsExpiredLicensesWithoutContact(): void
    {
        $repository = $this->createMock(LicenseDataRepositoryInterface::class);
        $repository->method('fetchData')->willReturnCallback(static function (string $extensionKey): array {
            if ($extensionKey !== 'ns_t3af') {
                return [];
            }

            return [[
                'license_key' => 'OLD',
                'is_life_time' => 0,
                'expiration_date' => 1,
                'name' => 'Expired',
                'email' => 'expired@example.com',
            ]];
        });

        $resolver = new LicenseContactResolver($repository);

        self::assertSame([], $resolver->resolve());
    }
}
