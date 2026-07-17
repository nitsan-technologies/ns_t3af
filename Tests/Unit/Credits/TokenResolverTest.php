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
use NITSAN\NsT3AF\Credits\Service\LicenseKeyResolver;
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
        $api->expects(self::once())->method('issueToken')->with('KEY-A', 'example.org')->willReturn([
            'token' => 'bearer-abc',
        ]);

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'license_keys' => 'KEY-A',
            'token_enc' => '',
            't3planet_api_base_url' => 'https://composer.example',
        ]);

        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );

        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $domain = new CreditsDomainResolver($siteFinder, $runtime);
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);
        $apiResponseCache->expects(self::never())->method('flush');

        $licenseKeys = new LicenseKeyResolver(null);
        $resolver = new TokenResolver($api, $runtime, $domain, $licenseKeys, $cache, $apiResponseCache);

        self::assertSame('bearer-abc', $resolver->issueFreshToken('example.org'));
    }

    public function testSyncLicensePoolMintsWhenNoTokenExists(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::once())->method('issueToken')->with('KEY-A,KEY-B', 'example.org')->willReturn([
            'token' => 'bearer-new',
        ]);
        $api->expects(self::never())->method('attachLicenses');

        $storedLicenseKeys = '';
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturnCallback(
            static function () use (&$storedLicenseKeys): array {
                return [
                    'license_keys' => $storedLicenseKeys,
                    'token_enc' => '',
                    't3planet_api_base_url' => 'https://composer.example',
                ];
            },
        );
        $repository->method('updateSingleton')->willReturnCallback(
            static function (array $fields) use (&$storedLicenseKeys): void {
                if (isset($fields['license_keys'])) {
                    $storedLicenseKeys = (string) $fields['license_keys'];
                }
            },
        );

        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );

        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $domain = new CreditsDomainResolver($siteFinder, $runtime);
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);

        $licenseKeys = new LicenseKeyResolver(null);
        $resolver = new TokenResolver($api, $runtime, $domain, $licenseKeys, $cache, $apiResponseCache);

        $result = $resolver->syncLicensePool('KEY-A,KEY-B', 'example.org');

        self::assertSame('minted', $result['action']);
        self::assertSame('bearer-new', $result['token']);
        self::assertSame('KEY-A,KEY-B', $result['license_keys']);
    }

    public function testSyncLicensePoolAttachesOnlyNewKeys(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::never())->method('issueToken');
        $api->expects(self::once())->method('attachLicenses')->with(
            'example.org',
            'KEY-B',
            'existing-token',
        )->willReturn([
            'status' => true,
            'license_keys' => 'KEY-A,KEY-B',
            'newly_attached' => ['KEY-B'],
            'credits_added' => 100,
            'free_credits' => 197,
            'already_bound' => false,
        ]);

        $cipher = new CredentialCipher();
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'license_keys' => 'KEY-A',
            'token_enc' => $cipher->encrypt('existing-token'),
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $repository->expects(self::once())->method('updateSingleton')->with(['license_keys' => 'KEY-A,KEY-B']);

        $runtime = new RuntimeSettingsService(
            $repository,
            $cipher,
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );

        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $domain = new CreditsDomainResolver($siteFinder, $runtime);
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);
        $apiResponseCache->expects(self::once())->method('flush');

        $licenseKeys = new LicenseKeyResolver(null);
        $resolver = new TokenResolver($api, $runtime, $domain, $licenseKeys, $cache, $apiResponseCache);

        $result = $resolver->syncLicensePool('KEY-A,KEY-B', 'example.org');

        self::assertSame('attached', $result['action']);
        self::assertSame('KEY-A,KEY-B', $result['license_keys']);
        self::assertSame(['KEY-B'], $result['newly_attached']);
        self::assertSame(100, $result['credits_added']);
    }

    public function testSyncLicensePoolSkipsApiWhenAllKeysAlreadyStored(): void
    {
        $api = $this->createMock(T3PlanetApiClient::class);
        $api->expects(self::never())->method('issueToken');
        $api->expects(self::never())->method('attachLicenses');

        $cipher = new CredentialCipher();
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'license_keys' => 'KEY-A,KEY-B',
            'token_enc' => $cipher->encrypt('existing-token'),
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $repository->expects(self::never())->method('updateSingleton');

        $runtime = new RuntimeSettingsService(
            $repository,
            $cipher,
            new \TYPO3\CMS\Core\Configuration\ExtensionConfiguration(),
        );

        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $domain = new CreditsDomainResolver($siteFinder, $runtime);
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));
        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);
        $apiResponseCache->expects(self::never())->method('flush');

        $licenseKeys = new LicenseKeyResolver(null);
        $resolver = new TokenResolver($api, $runtime, $domain, $licenseKeys, $cache, $apiResponseCache);

        $result = $resolver->syncLicensePool('KEY-A,KEY-B', 'example.org');

        self::assertSame('unchanged', $result['action']);
        self::assertTrue($result['already_bound']);
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

        $siteFinder = $this->createMock(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $domain = new CreditsDomainResolver($siteFinder, $runtime);
        $cache = new \NITSAN\NsT3AF\Cache\Typo3CacheFacade(new NullFrontend('test'));

        $apiResponseCache = $this->createMock(\NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface::class);
        $apiResponseCache->expects(self::once())->method('flush');

        $licenseKeys = new LicenseKeyResolver(null);
        $resolver = new TokenResolver($api, $runtime, $domain, $licenseKeys, $cache, $apiResponseCache);
        $resolver->invalidate();
    }
}
