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
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsReleaseGate;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class CreditModeResolverTest extends TestCase
{
    public function testReleaseGateOffIgnoresEnabledRuntimeFlag(): void
    {
        $resolver = $this->resolver(creditMode: 1, plainToken: 'active-token', gateAvailable: false);

        self::assertFalse($resolver->isPubliclyAvailable());
        self::assertFalse($resolver->isEnabled());
        self::assertFalse($resolver->isActive());
    }

    public function testReleaseGateOnRespectsEnabledRuntimeFlag(): void
    {
        $resolver = $this->resolver(creditMode: 1, plainToken: 'active-token', gateAvailable: true);

        self::assertTrue($resolver->isPubliclyAvailable());
        self::assertTrue($resolver->isEnabled());
        self::assertTrue($resolver->isActive());
    }

    public function testProductionGateIsOffByDefault(): void
    {
        self::assertFalse((new CreditsReleaseGate())->isPubliclyAvailable());
    }

    private function resolver(int $creditMode, string $plainToken = '', bool $gateAvailable = true): CreditModeResolver
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'credit_mode' => $creditMode,
            'license_keys' => $creditMode === 1 ? 'key' : '',
            'token_enc' => '',
        ]);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static function (string $extensionKey, string $configKey) use ($plainToken): string {
                if ($extensionKey === 'ns_t3af' && $configKey === 't3planetApiToken') {
                    return $plainToken;
                }

                return '';
            },
        );

        return new CreditModeResolver(
            new RuntimeSettingsService(
                $repository,
                new CredentialCipher(),
                $extensionConfiguration,
            ),
            new StubCreditsReleaseGate($gateAvailable),
        );
    }
}
