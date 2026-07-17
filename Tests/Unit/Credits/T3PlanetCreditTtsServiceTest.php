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

use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Api\TtsResponse;
use NITSAN\NsT3AF\Api\TtsServiceInterface;
use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\ProxyTtsExecutor;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditTtsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class T3PlanetCreditTtsServiceTest extends TestCase
{
    public function testCreditModeOffForwardsToInnerService(): void
    {
        $expected = new TtsResponse('audio', 'audio/mpeg', 'tts-1', 'openai-1');

        $inner = $this->createMock(TtsServiceInterface::class);
        $inner->expects(self::once())->method('speak')->willReturn($expected);

        $proxy = $this->createMock(ProxyTtsExecutor::class);
        $proxy->expects(self::never())->method('speak');

        $service = new T3PlanetCreditTtsService($inner, $this->creditModeResolver(0), $proxy);

        $result = $service->speak('Hello', new TtsOptions(featureKey: 'media.tts'));

        self::assertSame($expected, $result);
    }

    public function testCreditModeOnRoutesThroughProxy(): void
    {
        $expected = new TtsResponse('credits-audio', 'audio/mpeg', 't3planet', 't3planet_credits');

        $inner = $this->createMock(TtsServiceInterface::class);
        $inner->expects(self::never())->method('speak');

        $proxy = $this->createMock(ProxyTtsExecutor::class);
        $proxy->expects(self::once())->method('speak')->willReturn($expected);

        $service = new T3PlanetCreditTtsService(
            $inner,
            $this->creditModeResolver(1, 'active-token'),
            $proxy,
        );

        $result = $service->speak('Hello', new TtsOptions(extensionKey: 'ns_t3aa', featureKey: 'media.tts'));

        self::assertSame($expected, $result);
    }

    private function creditModeResolver(int $creditMode, string $plainToken = ''): CreditModeResolver
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

        return new CreditModeResolver(new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            $extensionConfiguration,
        ), new StubCreditsReleaseGate($creditMode === 1));
    }
}
