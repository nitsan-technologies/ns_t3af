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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\CreditsFeatureKeyCatalog;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Http\T3PlanetSseStreamParser;
use NITSAN\NsT3AF\Credits\Service\ProxyAiExecutor;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class ProxyAiExecutorLegacyUnwrapTest extends TestCase
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

    public function testCompleteUnwrapsSingleFieldJsonForLegacySeoKey(): void
    {
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->expects(self::once())
            ->method('charge')
            ->with(
                self::anything(),
                self::anything(),
                CreditsFeatureKeyCatalog::SEO_PAGE_METADATA,
                self::callback(static fn(array $meta): bool => ($meta['fields'] ?? []) === ['meta_description']),
                self::anything(),
                self::anything(),
            )
            ->willReturn([
                'status' => true,
                'model' => 'gpt-4o',
                'content' => json_encode(['meta_description' => 'A concise summary'], JSON_THROW_ON_ERROR),
                'credits' => ['free' => 10.0],
                'charged' => ['amount' => 1, 'feature_key' => CreditsFeatureKeyCatalog::SEO_PAGE_METADATA],
            ]);

        $executor = new ProxyAiExecutor(
            $apiClient,
            new T3PlanetSseStreamParser(),
            $this->tokenResolverWithBearer(),
            $this->domainResolver(),
            $this->chargeRecorderExpectingInsert(CreditsFeatureKeyCatalog::SEO_PAGE_METADATA),
            $this->createMock(EventDispatcherInterface::class),
            $this->telemetryService(),
            $this->featureKeyMapper(),
            $this->createMock(LoggerInterface::class),
        );

        $response = $executor->complete(
            'Page about TYPO3',
            new AiOptions(featureKey: 'seo.meta_description', extensionKey: 'ns_t3ai'),
        );

        self::assertSame('A concise summary', $response->content);
    }
}
