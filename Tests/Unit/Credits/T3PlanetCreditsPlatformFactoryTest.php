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

use NITSAN\NsT3AF\Credits\Platform\T3PlanetCreditsPlatformFactory;
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use PHPUnit\Framework\TestCase;

final class T3PlanetCreditsPlatformFactoryTest extends TestCase
{
    use CreditsModeResolverFixtureTrait;

    public function testApiRootAppendsApiAiPath(): void
    {
        $factory = new T3PlanetCreditsPlatformFactory(
            $this->runtimeSettingsWithBaseUrl('https://composer.example.com'),
            $this->createMock(TokenResolver::class),
            $this->createMock(CreditsDomainResolver::class),
        );

        self::assertSame('https://composer.example.com/API/AI', $factory->apiRoot());
    }

    public function testHttpClientAcceptsDomain(): void
    {
        $factory = new T3PlanetCreditsPlatformFactory(
            $this->runtimeSettingsWithBaseUrl('https://composer.example.com'),
            $this->createMock(TokenResolver::class),
            $this->createMock(CreditsDomainResolver::class),
        );

        $client = $factory->httpClient('example.com');
        self::assertInstanceOf(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $client);
    }
}
