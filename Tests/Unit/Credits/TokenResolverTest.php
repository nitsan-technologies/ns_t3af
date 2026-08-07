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

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Service\CreditsDomainResolver;
use NITSAN\NsT3AF\Credits\Service\LicenseContactResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Credits\Service\TokenResolver;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;

final class TokenResolverTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key-' . str_repeat('x', 32);
    }

    protected function tearDown(): void
    {
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testIssueFreshTokenStoresEncryptedToken(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::once())->method('issueTrialToken')->with(null, [])->willReturn([
            'token' => 'bearer-abc',
        ]);

        [$runtime, $cache, $apiResponseCache] = $this->runtimeFixtures();
        $resolver = new TokenResolver(
            $api,
            $runtime,
            $cache,
            $apiResponseCache,
            $this->domainResolver($runtime),
            $this->contactResolver(),
        );

        self::assertSame('bearer-abc', $resolver->issueFreshToken());
    }

    public function testActivateTrialTokenMintsWhenNoTokenExists(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::once())->method('issueTrialToken')->with(null, [])->willReturn([
            'token' => 'bearer-new',
        ]);

        [$runtime, $cache, $apiResponseCache] = $this->runtimeFixtures();
        $resolver = new TokenResolver(
            $api,
            $runtime,
            $cache,
            $apiResponseCache,
            $this->domainResolver($runtime),
            $this->contactResolver(),
        );

        $result = $resolver->activateTrialToken();

        self::assertSame('minted', $result['action']);
        self::assertSame('bearer-new', $result['token']);
    }

    public function testActivateTrialTokenBindsIpWhenTokenExists(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::never())->method('issueTrialToken');
        $api->expects(self::once())->method('bindIp')->willReturn([
            'bound_ip' => '127.0.0.1',
            'already_bound' => false,
        ]);

        $cipher = new CredentialCipher();
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'token_enc' => $cipher->encrypt('existing-token'),
            't3planet_api_base_url' => 'https://composer.example',
        ]);

        $runtime = new RuntimeSettingsService(
            $repository,
            $cipher,
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);

        $resolver = new TokenResolver(
            $api,
            $runtime,
            $cache,
            $apiResponseCache,
            $this->domainResolver($runtime),
            $this->contactResolver(),
        );
        $result = $resolver->activateTrialToken();

        self::assertSame('unchanged', $result['action']);
        self::assertTrue($result['already_bound'] ?? false);
        self::assertSame('127.0.0.1', $result['bound_ip'] ?? '');
    }

    public function testInvalidateClearsTokenAndFlushesApiResponseCache(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn(['uid' => 1, 'token_enc' => '']);
        $repository->expects(self::once())->method('updateSingleton')->with(['token_enc' => '']);

        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);
        $apiResponseCache->expects(self::once())->method('flush');

        $resolver = new TokenResolver(
            $api,
            $runtime,
            $cache,
            $apiResponseCache,
            $this->domainResolver($runtime),
            $this->contactResolver(),
        );
        $resolver->invalidate();
    }

    public function testIssueFreshTokenForwardsResolvedContact(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::once())->method('issueTrialToken')->with(null, [
            'name' => 'Jane',
            'email' => 'jane@example.com',
        ])->willReturn(['token' => 'bearer-with-contact']);

        [$runtime, $cache, $apiResponseCache] = $this->runtimeFixtures();
        $licenses = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\LicenseDataRepositoryInterface::class);
        $licenses->method('fetchData')->willReturnCallback(static function (string $extensionKey): array {
            if ($extensionKey !== 'ns_t3af') {
                return [];
            }

            return [[
                'license_key' => 'T3AF-1',
                'is_life_time' => 1,
                'expiration_date' => 0,
                'name' => 'Jane',
                'email' => 'jane@example.com',
            ]];
        });

        $resolver = new TokenResolver(
            $api,
            $runtime,
            $cache,
            $apiResponseCache,
            $this->domainResolver($runtime),
            new LicenseContactResolver($licenses),
        );

        self::assertSame('bearer-with-contact', $resolver->issueFreshToken());
    }

    public function testRefreshBearerTokenStoresNewSecret(): void
    {
        $cipher = new CredentialCipher();
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'token_enc' => $cipher->encrypt('old-token-' . str_repeat('0', 54)),
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $repository->expects(self::once())->method('updateSingleton')->with(self::callback(
            static fn(array $fields): bool => isset($fields['token_enc']) && $fields['token_enc'] !== '',
        ));

        $runtime = new RuntimeSettingsService(
            $repository,
            $cipher,
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );

        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::once())->method('bindIp')->willReturn([
            'bound_ip' => '127.0.0.1',
            'already_bound' => true,
        ]);
        $api->expects(self::once())->method('refreshBearerToken')->with(
            'old-token-' . str_repeat('0', 54),
            self::anything(),
            [],
        )->willReturn([
            'token' => 'new-token-' . str_repeat('1', 54),
        ]);

        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);
        $apiResponseCache->expects(self::once())->method('flush');

        $resolver = new TokenResolver(
            $api,
            $runtime,
            $cache,
            $apiResponseCache,
            $this->domainResolver($runtime),
            $this->contactResolver(),
        );
        $token = $resolver->refreshBearerToken();

        self::assertSame('new-token-' . str_repeat('1', 54), $token);
    }

    private function domainResolver(RuntimeSettingsService $runtime): CreditsDomainResolver
    {
        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        return new CreditsDomainResolver($siteFinder, $runtime);
    }

    private function contactResolver(): LicenseContactResolver
    {
        return new LicenseContactResolver(null);
    }

    /**
     * @return array{0: RuntimeSettingsService, 1: \NITSAN\NsT3AF\Cache\Typo3CacheFacade, 2: \NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function runtimeFixtures(): array
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'token_enc' => '',
            't3planet_api_base_url' => 'https://composer.example',
        ]);

        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);

        return [$runtime, $cache, $apiResponseCache];
    }
}
