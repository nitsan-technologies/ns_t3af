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

use NITSAN\NsT3AF\Api\ImageGenerationOptions;
use NITSAN\NsT3AF\Api\ImageGenerationResponse;
use NITSAN\NsT3AF\Api\ImageGenerationServiceInterface;
use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\ProxyImageExecutor;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditImageService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class T3PlanetCreditImageServiceTest extends TestCase
{
    public function testCreditModeOffForwardsToInnerService(): void
    {
        $expected = new ImageGenerationResponse(
            images: [['url' => 'https://example/image.png']],
            modelId: 'gpt-image-1',
            providerIdentifier: 'openai-1',
        );

        $inner = $this->createMock(ImageGenerationServiceInterface::class);
        $inner->expects(self::once())->method('generate')->willReturn($expected);

        $proxy = $this->createMock(ProxyImageExecutor::class);
        $proxy->expects(self::never())->method('generate');

        $service = new T3PlanetCreditImageService($inner, $this->creditModeResolver(0), $proxy);

        $result = $service->generate('prompt', new ImageGenerationOptions(featureKey: 'media.dalle'));

        self::assertSame($expected, $result);
    }

    public function testCreditModeOnRoutesThroughProxy(): void
    {
        $expected = new ImageGenerationResponse(
            images: [['b64_json' => base64_encode('png')]],
            modelId: 'gpt-image-1',
            providerIdentifier: 't3planet_credits',
        );

        $inner = $this->createMock(ImageGenerationServiceInterface::class);
        $inner->expects(self::never())->method('generate');

        $proxy = $this->createMock(ProxyImageExecutor::class);
        $proxy->expects(self::once())->method('generate')->willReturn($expected);

        $service = new T3PlanetCreditImageService(
            $inner,
            $this->creditModeResolver(1, 'active-token'),
            $proxy,
        );

        $result = $service->generate('prompt', new ImageGenerationOptions(extensionKey: 'ns_t3ai', featureKey: 'media.dalle'));

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
