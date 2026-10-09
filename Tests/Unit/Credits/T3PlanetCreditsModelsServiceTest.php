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

use NITSAN\NsT3AF\Credits\Http\T3PlanetHttpClient;
use NITSAN\NsT3AF\Credits\Platform\T3PlanetCreditsPlatformFactory;
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditsModelsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class T3PlanetCreditsModelsServiceTest extends TestCase
{
    public function testParseModelsAndPreferAgentStandard(): void
    {
        $http = $this->createMock(T3PlanetHttpClient::class);
        $http->method('getJson')->willReturn([
            'data' => [
                [
                    'id' => 't3planet/agent-fast',
                    'name' => 'Agent Fast',
                    'supports_tools' => true,
                    'supports_stream' => true,
                ],
                [
                    'id' => T3PlanetCreditsPlatformFactory::DEFAULT_MODEL_ALIAS,
                    'name' => 'Agent Standard',
                    'supports_tools' => true,
                    'supports_stream' => false,
                ],
                [
                    'id' => 't3planet/agent-no-tools',
                    'supports_tools' => false,
                ],
            ],
        ]);

        $token = $this->createMock(TokenResolver::class);
        $token->method('resolve')->willReturn('abc');
        $domain = $this->createMock(CreditsDomainResolver::class);
        $domain->method('resolve')->willReturn('example.com');

        $service = new T3PlanetCreditsModelsService($http, $token, $domain, new NullLogger());

        $tools = $service->listModels(true);
        self::assertCount(2, $tools);
        self::assertSame(T3PlanetCreditsPlatformFactory::DEFAULT_MODEL_ALIAS, $service->resolveDefaultAlias());
        self::assertTrue($service->isKnownAlias('t3planet/agent-fast'));
    }

    public function testFallbackWhenModelsCallFails(): void
    {
        $http = $this->createMock(T3PlanetHttpClient::class);
        $http->method('getJson')->willThrowException(new \RuntimeException('down'));

        $service = new T3PlanetCreditsModelsService(
            $http,
            $this->createMock(TokenResolver::class),
            $this->createMock(CreditsDomainResolver::class),
            new NullLogger(),
        );

        self::assertSame(T3PlanetCreditsPlatformFactory::DEFAULT_MODEL_ALIAS, $service->resolveDefaultAlias());
        self::assertCount(1, $service->listModels());
    }
}
