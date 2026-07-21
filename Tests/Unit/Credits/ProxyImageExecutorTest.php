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
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Service\ProxyImageExecutor;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class ProxyImageExecutorTest extends TestCase
{
    use CreditsProxyTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCreditsProxyFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownCreditsProxyFixtures();
        parent::tearDown();
    }

    public function testGenerateSuccessRecordsReceipt(): void
    {
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->expects(self::once())
            ->method('generateImage')
            ->willReturn([
                'status' => true,
                'images' => [['b64_json' => base64_encode('png')]],
                'model' => 'gpt-image-1',
                'credits' => ['free' => 40],
                'charged' => ['amount' => 50, 'model' => 'gpt-image-1'],
            ]);

        $executor = new ProxyImageExecutor(
            $apiClient,
            $this->tokenResolverWithBearer(),
            $this->domainResolver(),
            $this->featureKeyMapper(),
            $this->chargeRecorderExpectingInsert('image_generation'),
            $this->createMock(EventDispatcherInterface::class),
            $this->telemetryService(),
            $this->createMock(LoggerInterface::class),
        );

        $response = $executor->generate(
            'A red balloon',
            new ImageGenerationOptions(extensionKey: 'ns_t3ai', featureKey: 'media.dalle'),
        );

        self::assertSame('gpt-image-1', $response->modelId);
        self::assertCount(1, $response->images);
    }
}
