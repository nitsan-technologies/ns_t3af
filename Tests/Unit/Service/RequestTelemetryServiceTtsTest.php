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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Api\CreditsUsage;
use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Api\TtsResponse;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Service\RequestQualityResolver;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RequestTelemetryServiceTtsTest extends TestCase
{
    /** @var array<string, int|float|string|null> */
    private array $captured = [];

    public function testLogTtsPersistsCreditsAndTokensFromResponse(): void
    {
        $service = new RequestTelemetryService(
            $this->capturingRepository(),
            new RequestQualityResolver(),
        );

        $service->logTts(
            $this->creditsProvider(),
            new TtsOptions(extensionKey: 'ns_t3aa', featureKey: 'media.tts'),
            'Hello world',
            new TtsResponse(
                audio: 'binary',
                mimeType: 'audio/mpeg',
                modelId: 'tts-1',
                providerIdentifier: 't3planet_credits',
                latencyMs: 9610,
                tokensInput: 145,
                tokensTotal: 145,
                credits: $this->creditsUsage(146, 0.146, 145),
            ),
        );

        self::assertSame(145, $this->captured['prompt_tokens']);
        self::assertSame(0, $this->captured['completion_tokens']);
        self::assertSame(145, $this->captured['total_tokens']);
        self::assertSame(0.146, $this->captured['credits_used']);
        self::assertSame(0.146, $this->captured['estimated_cost']);
    }

    public function testLogTtsKeepsZeroForByoResponseWithoutCredits(): void
    {
        $service = new RequestTelemetryService(
            $this->capturingRepository(),
            new RequestQualityResolver(),
        );

        $service->logTts(
            $this->creditsProvider(),
            new TtsOptions(extensionKey: 'ns_t3aa', featureKey: 'media.tts'),
            'Hello world',
            new TtsResponse('binary', 'audio/mpeg', 'tts-1', 'openai-1'),
        );

        self::assertSame(0, $this->captured['prompt_tokens']);
        self::assertSame(0, $this->captured['total_tokens']);
        self::assertSame(0.0, $this->captured['credits_used']);
        self::assertSame(0.0, $this->captured['estimated_cost']);
    }

    private function capturingRepository(): RequestLogRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(
            function (string $table, array $payload): int {
                $this->captured = $payload;

                return 1;
            },
        );

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        return new RequestLogRepository($connectionPool);
    }

    private function creditsProvider(): Provider
    {
        return Provider::fromRow([
            'uid' => 0,
            'identifier' => 't3planet_credits',
            'title' => 'T3Planet Credits',
            'adapter_type' => 't3planet.credits',
            'model_id' => 't3planet',
        ]);
    }

    private function creditsUsage(int $chargedUnits, float $charged, int $tokensTotal): CreditsUsage
    {
        return new CreditsUsage(
            chargedUnits: $chargedUnits,
            charged: $charged,
            bucket: 'plan',
            featureKey: 'text_to_speech',
            serverRequestId: 'req-uuid',
            balanceFreeUnits: 0,
            balanceFree: 0.0,
            balancePaidUnits: 0,
            balancePaid: 0.0,
            planUsedUnits: 0,
            planUsed: 0.0,
            planTotalUnits: 0,
            planTotal: 0.0,
            planName: 'test',
            planExpiresAt: 0,
            tokensInput: $tokensTotal,
            tokensOutput: 0,
            tokensTotal: $tokensTotal,
        );
    }
}
