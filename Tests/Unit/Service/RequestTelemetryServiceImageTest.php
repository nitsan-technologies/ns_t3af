<?php

declare(strict_types=1);

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Api\CreditsUsage;
use NITSAN\NsT3AF\Api\ImageGenerationOptions;
use NITSAN\NsT3AF\Api\ImageGenerationResponse;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Service\RequestQualityResolver;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RequestTelemetryServiceImageTest extends TestCase
{
    /** @var array<string, int|float|string|null> */
    private array $captured = [];

    public function testLogImageGenerationPersistsCreditsFromResponse(): void
    {
        $service = new RequestTelemetryService(
            $this->capturingRepository(),
            new RequestQualityResolver(),
        );

        $service->logImageGeneration(
            $this->creditsProvider(),
            new ImageGenerationOptions(
                extensionKey: 'ns_t3ai',
                featureKey: 'image_generate',
                requestSource: 'backend_module',
            ),
            'A red cat',
            new ImageGenerationResponse(
                images: [['url' => 'https://example.test/cat.png']],
                modelId: 'gpt-image-1',
                providerIdentifier: 't3planet_credits',
                latencyMs: 44361,
                credits: $this->creditsUsage(5890, 5.89),
            ),
            'generate',
        );

        self::assertSame('image_generation', $this->captured['request_type']);
        self::assertSame('image_generate', $this->captured['feature_key']);
        self::assertSame(5.89, $this->captured['credits_used']);
        self::assertSame(5.89, $this->captured['estimated_cost']);
    }

    public function testLogImageGenerationKeepsZeroForByoResponseWithoutCredits(): void
    {
        $service = new RequestTelemetryService(
            $this->capturingRepository(),
            new RequestQualityResolver(),
        );

        $service->logImageGeneration(
            $this->creditsProvider(),
            new ImageGenerationOptions(extensionKey: 'ns_t3ai', featureKey: 'image_generate'),
            'A red cat',
            new ImageGenerationResponse(
                images: [['url' => 'https://example.test/cat.png']],
                modelId: 'gpt-image-1',
                providerIdentifier: 'openai-1',
                latencyMs: 1200,
            ),
            'generate',
        );

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

    private function creditsUsage(int $chargedUnits, float $charged): CreditsUsage
    {
        return new CreditsUsage(
            chargedUnits: $chargedUnits,
            charged: $charged,
            bucket: 'plan',
            featureKey: 'image_generate',
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
            tokensInput: 0,
            tokensOutput: 0,
            tokensTotal: 0,
        );
    }
}
