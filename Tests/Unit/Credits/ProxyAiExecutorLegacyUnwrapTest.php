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
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Exception\CreditsContentRemovedException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Http\T3PlanetSseStreamParser;
use NITSAN\NsT3AF\Credits\Service\CreditsChargeRecorder;
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

    public function testCompleteThrowsContentRemovedOnRedactedReplayWithoutReceipt(): void
    {
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->method('charge')->willReturn([
            'status' => true,
            'content' => '',
            'content_removed' => true,
            'warnings' => [['code' => 'content_redacted_retention', 'message' => 'removed']],
            'credits' => [],
            'charged' => ['amount' => 1],
        ]);

        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $connection->expects(self::never())->method('insert');
        $pool = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $executor = new ProxyAiExecutor(
            $apiClient,
            new T3PlanetSseStreamParser(),
            $this->tokenResolverWithBearer(),
            $this->domainResolver(),
            new \NITSAN\NsT3AF\Credits\Service\CreditsChargeRecorder(new \NITSAN\NsT3AF\Credits\Service\LocalReceiptCache($pool)),
            $this->createMock(EventDispatcherInterface::class),
            $this->telemetryService(),
            $this->featureKeyMapper(),
            $this->createMock(LoggerInterface::class),
        );

        try {
            $executor->complete('Page about TYPO3', new AiOptions(featureKey: 'seo.meta_description', extensionKey: 'ns_t3ai'));
            self::fail('Expected CreditsContentRemovedException');
        } catch (CreditsContentRemovedException $exception) {
            self::assertSame('content_removed', $exception->errorCode);
            self::assertSame('content_redacted_retention', $exception->extra['warnings'][0]['code']);
        }
    }

    public function testCompleteRetriesTransientUpstreamErrorOnceWithFreshUuid(): void
    {
        $uuids = [];
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->expects(self::exactly(2))
            ->method('charge')
            ->willReturnCallback(static function (string $domain, string $uuid) use (&$uuids): array {
                $uuids[] = $uuid;
                if (count($uuids) === 1) {
                    throw new CreditsApiException('upstream_ai_error', 502, 'boom', ['upstream_status' => 503]);
                }

                return [
                    'status' => true,
                    'model' => 'gpt-4o',
                    'content' => 'ok',
                    'credits' => [],
                    'charged' => ['amount' => 1, 'feature_key' => CreditsFeatureKeyCatalog::SEO_PAGE_METADATA],
                ];
            });

        $executor = $this->executorFor($apiClient);
        $response = $executor->complete('Page', new AiOptions(featureKey: 'seo.meta_description', extensionKey: 'ns_t3ai'));

        self::assertSame('ok', $response->content);
        self::assertNotSame($uuids[0], $uuids[1]);
    }

    public function testCompleteDoesNotRetryContextLengthExceeded(): void
    {
        $apiClient = $this->createMock(T3PlanetApiClient::class);
        $apiClient->expects(self::once())
            ->method('charge')
            ->willThrowException(new CreditsApiException('context_length_exceeded', 422, 'too long', ['upstream_status' => 503]));

        $executor = $this->executorFor($apiClient);

        $this->expectException(CreditsApiException::class);
        $executor->complete('Page', new AiOptions(featureKey: 'seo.meta_description', extensionKey: 'ns_t3ai'));
    }

    private function executorFor(T3PlanetApiClient $apiClient): ProxyAiExecutor
    {
        return new ProxyAiExecutor(
            $apiClient,
            new T3PlanetSseStreamParser(),
            $this->tokenResolverWithBearer(),
            $this->domainResolver(),
            $this->createMock(CreditsChargeRecorder::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->telemetryService(),
            $this->featureKeyMapper(),
            $this->createMock(LoggerInterface::class),
        );
    }
}
