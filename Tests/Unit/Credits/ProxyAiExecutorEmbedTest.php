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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Http\T3PlanetSseStreamParser;
use NITSAN\NsT3AF\Credits\Service\ProxyAiExecutor;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class ProxyAiExecutorEmbedTest extends TestCase
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

    public function testEmbedSuccessRecordsReceipt(): void
    {
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->expects(self::once())
            ->method('embed')
            ->willReturn([
                'status' => true,
                'model' => 'text-embedding-3-small',
                'vectors' => [[0.1, 0.2]],
                'tokens_input' => 12,
                'credits' => ['free' => 10],
                'charged' => ['amount' => 1, 'model' => 'text-embedding-3-small', 'tokens_total' => 12],
            ]);

        $executor = new ProxyAiExecutor(
            $apiClient,
            new T3PlanetSseStreamParser(),
            $this->tokenResolverWithBearer(),
            $this->domainResolver(),
            $this->chargeRecorderExpectingInsert('embedding'),
            $this->createMock(EventDispatcherInterface::class),
            $this->telemetryService(),
            $this->featureKeyMapper(),
            $this->createMock(LoggerInterface::class),
        );

        $response = $executor->embed('hello world', new AiOptions(featureKey: 'embed', extensionKey: 'ns_t3cs'));

        self::assertSame('text-embedding-3-small', $response->modelId);
        self::assertCount(1, $response->vectors);
    }
}
