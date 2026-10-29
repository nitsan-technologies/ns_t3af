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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Service\CreditsApiErrorMessageResolver;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Service\RequestQualityResolver;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RequestTelemetryServiceFailureMessageTest extends TestCase
{
    /** @var array<string, int|float|string|null> */
    private array $captured = [];

    /** @var array<string, mixed>|null */
    private ?array $previousLang = null;

    protected function setUp(): void
    {
        $this->previousLang = $GLOBALS['LANG'] ?? null;
        $GLOBALS['LANG'] = new class {
            public function sL(string $label): string
            {
                return match (true) {
                    str_contains($label, 'rate_limited') => 'Wait %s.',
                    str_contains($label, 'concurrency_limit') => 'Too many AI requests are in progress.',
                    str_contains($label, 'api_error') => 'Generic API error.',
                    default => '',
                };
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->previousLang === null) {
            unset($GLOBALS['LANG']);
        } else {
            $GLOBALS['LANG'] = $this->previousLang;
        }
    }

    public function testLogFailureUsesRateLimitText(): void
    {
        $this->log(new CreditsApiException('rate_limited', 429, 'rate_limited', ['retry_after' => 45]));

        self::assertSame('Wait 45 seconds.', $this->userMessage());
    }

    public function testLogFailureUsesConcurrencyTextFromWrappedException(): void
    {
        $this->log(new \RuntimeException(
            'wrapper',
            0,
            new CreditsApiException('concurrency_limit', 429, 'concurrency_limit'),
        ));

        self::assertSame('Too many AI requests are in progress.', $this->userMessage());
    }

    public function testLogFailureKeepsGenericTextForOtherErrors(): void
    {
        $this->log(new \RuntimeException('provider exploded'));

        self::assertSame('The AI request failed.', $this->userMessage());
    }

    public function testLogFailureKeepsGenericTextForOtherCreditsCodes(): void
    {
        $this->log(new CreditsApiException('insufficient_credits', 402, 'insufficient_credits'));

        self::assertSame('The AI request failed.', $this->userMessage());
    }

    public function testLogFailureKeepsGenericTextWhenLanguageServiceIsMissing(): void
    {
        unset($GLOBALS['LANG']);
        $this->log(new CreditsApiException('rate_limited', 429, 'rate_limited', ['retry_after' => 45]));

        self::assertSame('The AI request failed.', $this->userMessage());
    }

    private function log(\Throwable $error): void
    {
        $service = new RequestTelemetryService(
            $this->capturingRepository(),
            new RequestQualityResolver(),
            new CreditsApiErrorMessageResolver(),
        );
        $service->logFailure(
            Provider::fromRow([
                'uid' => 0,
                'identifier' => 't3planet_credits',
                'title' => 'T3Planet Credits',
                'adapter_type' => 't3planet.credits',
                'model_id' => 't3planet',
            ]),
            new AiOptions(),
            'prompt',
            'complete',
            $error,
        );
    }

    private function userMessage(): string
    {
        $meta = json_decode((string) ($this->captured['raw_meta'] ?? ''), true);

        return is_array($meta) ? (string) ($meta['user_message'] ?? '') : '';
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
}
