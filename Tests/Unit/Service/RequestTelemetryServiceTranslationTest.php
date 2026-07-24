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

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Service\RequestQualityResolver;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RequestTelemetryServiceTranslationTest extends TestCase
{
    /** @var array<string, int|float|string|null> */
    private array $captured = [];

    public function testLogTranslationPersistsTranslateRequestType(): void
    {
        $service = new RequestTelemetryService(
            $this->capturingRepository(),
            new RequestQualityResolver(),
        );

        $service->logTranslation(
            $this->provider(),
            'ns_t3ai',
            'translation.deepl',
            'Hello world',
            'Hallo Welt',
            'backend_localization',
            120,
            'en',
            'de',
        );

        self::assertSame('translate', $this->captured['request_type']);
        self::assertSame('ns_t3ai', $this->captured['extension_key']);
        self::assertSame('translation.deepl', $this->captured['feature_key']);
        self::assertSame('DeepL', $this->captured['provider_identifier']);
        self::assertSame(11, $this->captured['prompt_tokens']);
        self::assertSame(10, $this->captured['completion_tokens']);
        self::assertSame(21, $this->captured['total_tokens']);
        self::assertSame(120, $this->captured['latency_ms']);
    }

    private function provider(): Provider
    {
        return Provider::fromRow([
            'uid' => 15,
            'identifier' => 'DeepL',
            'title' => 'DeepL',
            'adapter_type' => 'ns_t3ai.deepl_translate',
            'model_id' => 'deepl',
            'is_enabled' => 1,
            'last_status' => 'connected',
        ]);
    }

    private function capturingRepository(): RequestLogRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(
            function (string $table, array $row): int {
                $this->captured = $row;

                return 1;
            },
        );

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        return new RequestLogRepository($connectionPool);
    }
}
