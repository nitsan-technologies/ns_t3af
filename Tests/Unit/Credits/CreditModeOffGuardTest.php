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
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Credits\Service\ProxyAiExecutor;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\T3PlanetCreditAiService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CreditModeOffGuardTest extends TestCase
{
    public function testCreditModeOffForwardsToInnerService(): void
    {
        $inner = $this->createMock(AiServiceInterface::class);
        $inner->expects(self::once())->method('complete')->willReturn(
            new AiResponse('ok', 'm', 'p'),
        );

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn(['credit_mode' => 0, 'license_keys' => '', 'token_enc' => '']);
        $runtime = new RuntimeSettingsService($repository, new CredentialCipher(), new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration());
        $mode = new CreditModeResolver($runtime);

        $proxy = $this->createMock(ProxyAiExecutor::class);
        $proxy->expects(self::never())->method('complete');

        $service = new T3PlanetCreditAiService($inner, $mode, $proxy);

        $response = $service->complete('hello', new AiOptions(featureKey: 'test.feature'));

        self::assertSame('ok', $response->content);
    }
}
