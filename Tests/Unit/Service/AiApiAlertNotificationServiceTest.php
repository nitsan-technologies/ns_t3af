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

use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Service\AiApiAlertNotificationService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\ProviderLegacyConfigService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class AiApiAlertNotificationServiceTest extends TestCase
{
    private AiApiAlertNotificationService $service;

    protected function setUp(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $providers = $this->createMock(ProviderLookupInterface::class);
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findAll')->willReturn([]);
        $repo->method('findDefault')->willReturn(null);
        $legacyConfig = new ProviderLegacyConfigService($repo, new CredentialCipher());
        $this->service = new AiApiAlertNotificationService($cache, $providers, $legacyConfig);
    }

    public function testClassifyErrorReturnsNullForUnrelatedMessage(): void
    {
        self::assertNull($this->service->classifyError('Connection timed out'));
    }

    public function testClassifyErrorDetectsQuota(): void
    {
        self::assertSame('quota', $this->service->classifyError('You exceeded your current quota, please check your plan.'));
        self::assertSame('quota', $this->service->classifyError('insufficient_quota'));
    }

    public function testClassifyErrorDetectsApiKey(): void
    {
        self::assertSame('api_key', $this->service->classifyError('Incorrect API key provided'));
        self::assertSame('api_key', $this->service->classifyError('Invalid authorization header'));
    }
}
