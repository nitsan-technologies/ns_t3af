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

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

trait CreditsModeResolverFixtureTrait
{
    private function creditModeResolver(bool $active): CreditModeResolver
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'credit_mode' => $active ? 1 : 0,
            'license_keys' => $active ? 'key' : '',
            'token_enc' => '',
        ]);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static function (string $extensionKey, string $configKey) use ($active): string {
                if ($extensionKey === 'ns_t3af' && $configKey === 't3planetApiToken') {
                    return $active ? 'token-plain' : '';
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
            new StubCreditsReleaseGate($active),
        );
    }

    private function runtimeSettingsWithBaseUrl(string $baseUrl): RuntimeSettingsService
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'credit_mode' => 0,
            'license_keys' => '',
            'token_enc' => '',
            't3planet_api_base_url' => $baseUrl,
        ]);

        return new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            $this->createMock(ExtensionConfiguration::class),
        );
    }
}
