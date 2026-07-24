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

use NITSAN\NsT3AF\Credits\CreditsConstants;
use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Service\CreditsApiBaseUrlResolver;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;

final class RuntimeSettingsServiceApiBaseUrlTest extends TestCase
{
    /** @var array<string, string|int> */
    private array $row = [
        'credit_mode' => 0,
        'license_keys' => '',
        'token_enc' => '',
        't3planet_api_base_url' => '',
    ];

    public function testSyncUpdatesBetaspaceToProductionOnProductionContext(): void
    {
        $this->row['t3planet_api_base_url'] = CreditsConstants::STAGING_API_BASE_URL;

        $service = $this->createService(new ApplicationContext('Production'));

        self::assertSame(CreditsConstants::DEFAULT_API_BASE_URL, $service->getApiBaseUrl());
        self::assertSame(CreditsConstants::DEFAULT_API_BASE_URL, $this->row['t3planet_api_base_url']);
    }

    public function testSyncUpdatesBetaspaceToStagingOnDevelopmentContext(): void
    {
        $this->row['t3planet_api_base_url'] = CreditsConstants::DEFAULT_API_BASE_URL;

        $service = $this->createService(new ApplicationContext('Development'));

        self::assertSame(CreditsConstants::STAGING_API_BASE_URL, $service->getApiBaseUrl());
        self::assertSame(CreditsConstants::STAGING_API_BASE_URL, $this->row['t3planet_api_base_url']);
    }

    public function testEnvironmentOverrideSyncsDatabaseRow(): void
    {
        $previous = getenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE) ?: null;
        putenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE . '=' . CreditsConstants::LOCAL_DDEV_API_BASE_URL);

        try {
            $this->row['t3planet_api_base_url'] = CreditsConstants::STAGING_API_BASE_URL;
            $service = $this->createService(new ApplicationContext('Development'));

            self::assertSame(CreditsConstants::LOCAL_DDEV_API_BASE_URL, $service->getApiBaseUrl());
            self::assertSame(CreditsConstants::LOCAL_DDEV_API_BASE_URL, $this->row['t3planet_api_base_url']);
        } finally {
            if ($previous === null) {
                putenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE);
            } else {
                putenv(CreditsApiBaseUrlResolver::ENVIRONMENT_VARIABLE . '=' . $previous);
            }
        }
    }

    public function testCustomUrlIsNotOverwritten(): void
    {
        $custom = 'https://credits-staging.customer.example';
        $this->row['t3planet_api_base_url'] = $custom;

        $service = $this->createService(new ApplicationContext('Production'));

        self::assertSame($custom, $service->getApiBaseUrl());
        self::assertSame($custom, $this->row['t3planet_api_base_url']);
    }

    public function testLegacyExtensionConfigMigratesWhenDatabaseFieldIsEmpty(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')
            ->willReturnCallback(static function (string $extension, ?string $path = null): mixed {
                if ($path === 't3planetApiBaseUrl') {
                    return CreditsConstants::STAGING_API_BASE_URL;
                }

                return [];
            });

        $service = $this->createService(
            new ApplicationContext('Production'),
            $extensionConfiguration,
        );

        self::assertSame(CreditsConstants::DEFAULT_API_BASE_URL, $service->getApiBaseUrl());
        self::assertSame(CreditsConstants::DEFAULT_API_BASE_URL, $this->row['t3planet_api_base_url']);
    }

    private function createService(
        ApplicationContext $context,
        ?ExtensionConfiguration $extensionConfiguration = null,
    ): RuntimeSettingsService {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturnCallback(fn(): array => $this->row);
        $repository->method('updateSingleton')->willReturnCallback(function (array $fields): void {
            foreach ($fields as $key => $value) {
                $this->row[$key] = $value;
            }
        });

        return new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            $extensionConfiguration ?? new ExtensionConfiguration(),
            new CreditsApiBaseUrlResolver($context),
        );
    }
}
