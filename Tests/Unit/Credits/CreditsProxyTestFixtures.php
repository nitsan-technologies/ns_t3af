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

use NITSAN\NsT3AF\Cache\Typo3CacheFacade;
use NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface;
use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Service\CreditsChargeRecorder;
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\CreditsFeatureKeyMapper;
use NITSAN\NsT3AF\Credits\Service\LicenseKeyResolver;
use NITSAN\NsT3AF\Credits\Service\LocalReceiptCache;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use NITSAN\NsT3AF\Domain\Repository\RequestLogRepository;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\RequestQualityResolver;
use NITSAN\NsT3AF\Service\RequestTelemetryService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

trait CreditsProxyTestFixtures
{
    /** @var array<string, mixed>|null */
    private ?array $creditsProxyTypo3ConfVars = null;

    protected function setUpCreditsProxyFixtures(): void
    {
        $this->creditsProxyTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key-' . str_repeat('x', 32);
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases']);
    }

    protected function tearDownCreditsProxyFixtures(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases']);
        if ($this->creditsProxyTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->creditsProxyTypo3ConfVars;
        }
    }

    protected function tokenResolverWithBearer(string $token = 'bearer-test'): TokenResolver
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->method('issueToken')->willReturn(['token' => $token]);

        $resolver = new TokenResolver(
            $api,
            $this->runtimeSettings(),
            $this->domainResolver(),
            new LicenseKeyResolver(null),
            $this->tokenCache($token),
            $this->createMock(CreditsApiResponseCacheInterface::class),
        );
        $resolver->resolve();

        return $resolver;
    }

    protected function domainResolver(): CreditsDomainResolver
    {
        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        return new CreditsDomainResolver($siteFinder, $this->runtimeSettings());
    }

    protected function runtimeSettings(): RuntimeSettingsService
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'license_keys' => 'KEY-A',
            'token_enc' => '',
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $repository->method('updateSingleton')->willReturnCallback(static function (): void {});

        return new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new ExtensionConfiguration(),
        );
    }

    protected function tokenCache(string $token): Typo3CacheFacade
    {
        $cache = new Typo3CacheFacade(new NullFrontend('test'));
        $cache->set('t3planet_bearer_token', $token);

        return $cache;
    }

    protected function featureKeyMapper(): CreditsFeatureKeyMapper
    {
        return new CreditsFeatureKeyMapper(ProviderTestStubs::creditsAliasProviders());
    }

    protected function telemetryService(): RequestTelemetryService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturn(1);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        return new RequestTelemetryService(
            new RequestLogRepository($connectionPool),
            new RequestQualityResolver(),
        );
    }

    protected function chargeRecorderExpectingInsert(string $featureKey): CreditsChargeRecorder
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nst3af_credit_receipt',
                self::callback(static fn(array $row): bool => $row['feature_key'] === $featureKey),
            );
        $connection->method('count')->willReturn(1);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        return new CreditsChargeRecorder(new LocalReceiptCache($pool));
    }
}
