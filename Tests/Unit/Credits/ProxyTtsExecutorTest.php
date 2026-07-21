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
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Service\ProxyTtsExecutor;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class ProxyTtsExecutorTest extends TestCase
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

    public function testSpeakSuccessRecordsReceipt(): void
    {
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->expects(self::once())
            ->method('speak')
            ->willReturn([
                'status' => true,
                'audio_base64' => base64_encode('fake-mp3'),
                'mime_type' => 'audio/mpeg',
                'model' => 'tts-1-hd',
                'credits' => ['free' => 9],
                'charged' => ['amount' => 1, 'model' => 'tts-1-hd', 'tokens_total' => 19],
            ]);

        $executor = new ProxyTtsExecutor(
            $apiClient,
            $this->tokenResolverWithBearer(),
            $this->domainResolver(),
            $this->featureKeyMapper(),
            $this->chargeRecorderExpectingInsert('text_to_speech'),
            $this->createMock(EventDispatcherInterface::class),
            $this->telemetryService(),
            $this->createMock(LoggerInterface::class),
        );

        $response = $executor->speak('Hello', new TtsOptions(extensionKey: 'ns_t3aa', featureKey: 'media.tts'));

        self::assertSame('fake-mp3', $response->audio);
        self::assertSame('tts-1-hd', $response->modelId);
    }
}
