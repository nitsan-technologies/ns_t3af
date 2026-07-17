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

namespace NITSAN\NsT3AF\Tests\Unit\Settings;

use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\SiteExtensionSettingsResolver;
use NITSAN\NsT3AF\Service\SiteStorageContext;
use NITSAN\NsT3AF\Settings\ExtensionSettingsDynamicDefaultsRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRepository;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSchemaService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSecretRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSecretService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ExtensionSettingsServiceTest extends TestCase
{
    private function createService(
        ExtensionSettingsRegistry $registry,
        ExtensionSettingsRepository $repository,
        ExtensionSettingsSchemaService $schemaService,
        ?ExtensionSettingsDynamicDefaultsRegistry $dynamicDefaultsRegistry = null,
        ?SiteExtensionSettingsResolver $storageResolver = null,
    ): ExtensionSettingsService {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 32);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        return new ExtensionSettingsService(
            $registry,
            $repository,
            $schemaService,
            new SiteStorageContext($siteFinder),
            $dynamicDefaultsRegistry ?? new ExtensionSettingsDynamicDefaultsRegistry([]),
            $storageResolver ?? new SiteExtensionSettingsResolver(
                new SiteStorageContext($siteFinder),
                $siteFinder,
                $repository,
                ProviderTestStubs::t3AiStorageProbeRegistry(),
            ),
            new ExtensionSettingsSecretService(
                new ExtensionSettingsSecretRegistry([ProviderTestStubs::t3AiSecretProvider()]),
                new CredentialCipher(),
            ),
        );
    }

    public function testGetAllMergesSchemaDefaultsWithStoredValues(): void
    {
        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('isManaged')->with('ns_t3af')->willReturn(true);

        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $repository->method('findByExtensionKey')->with('ns_t3af', 0)->willReturn([
            'extension_key' => 'ns_t3af',
            'settings_json' => '{"basicAuthEnabled":"1"}',
        ]);

        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $schemaService->method('getDefaults')->with('ns_t3af')->willReturn([
            'basicAuthEnabled' => '0',
            'mcpBasePath' => '/mcp',
        ]);

        $service = $this->createService($registry, $repository, $schemaService);

        self::assertSame([
            'basicAuthEnabled' => '1',
            'mcpBasePath' => '/mcp',
        ], $service->getAll('ns_t3af'));
    }

    public function testMergePersistsDeltaAndUpdatesRequestCache(): void
    {
        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('isManaged')->with('ns_t3cs')->willReturn(true);

        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $repository->method('findByExtensionKey')->with('ns_t3cs', 68)->willReturn([
            'extension_key' => 'ns_t3cs',
            'settings_json' => '{"batchSize":"50"}',
        ]);
        $repository->expects(self::once())->method('updateSettingsJson')->with(
            'ns_t3cs',
            '{"batchSize":"100"}',
            68,
        );

        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $schemaService->method('getDefaults')->willReturn([
            'batchSize' => '100',
            'retentionDays' => '30',
        ]);

        $service = $this->createService($registry, $repository, $schemaService);
        $service->merge('ns_t3cs', ['batchSize' => '100'], 68);
    }

    public function testMergeGlobalPersistsToPidZeroAndExistingSiteRows(): void
    {
        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('isManaged')->with('ns_t3af')->willReturn(true);

        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $repository->method('findAllByExtensionKey')->with('ns_t3af')->willReturn([
            ['pid' => 23, 'extension_key' => 'ns_t3af', 'settings_json' => '{"mcpMode":"native"}'],
        ]);
        $repository->method('findByExtensionKey')->willReturnCallback(
            static function (string $extensionKey, int $pid): ?array {
                if ($extensionKey !== 'ns_t3af') {
                    return null;
                }

                return [
                    'extension_key' => $extensionKey,
                    'settings_json' => $pid === 23 ? '{"mcpMode":"native"}' : '{}',
                ];
            },
        );
        $updated = [];
        $repository->expects(self::exactly(2))->method('updateSettingsJson')->willReturnCallback(
            static function (string $extensionKey, string $json, int $pid) use (&$updated): void {
                $updated[] = [$extensionKey, $json, $pid];
            },
        );

        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $schemaService->method('getDefaults')->willReturn(['mcpMode' => 'context']);

        $service = $this->createService($registry, $repository, $schemaService);

        self::assertTrue($service->mergeGlobal('ns_t3af', ['mcpMode' => 'context']));
        self::assertContains(['ns_t3af', '{"mcpMode":"context"}', 23], $updated);
        self::assertContains(['ns_t3af', '{"mcpMode":"context"}', 0], $updated);
    }

    public function testMergeGlobalReturnsFalseForUnmanagedExtension(): void
    {
        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('isManaged')->willReturn(false);

        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $service = $this->createService($registry, $repository, $schemaService);

        self::assertFalse($service->mergeGlobal('unknown_ext', ['mcpMode' => 'context']));
    }

    public function testGetReturnsDefaultForUnknownPath(): void
    {
        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('isManaged')->willReturn(true);

        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $repository->method('findByExtensionKey')->willReturn(null);

        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $schemaService->method('getDefaults')->willReturn([]);

        $service = $this->createService($registry, $repository, $schemaService);

        self::assertSame('fallback', $service->get('ns_t3aa', 'missingKey', 'fallback'));
    }

    public function testGetAllMergesDynamicDefaultsBeforeStoredValues(): void
    {
        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('isManaged')->with('ns_t3ai')->willReturn(true);

        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $repository->method('findByExtensionKey')->with('ns_t3ai', 68)->willReturn([
            'extension_key' => 'ns_t3ai',
            'settings_json' => '{"enableAllInOneSeo":"0"}',
        ]);

        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $schemaService->method('getDefaults')->with('ns_t3ai')->willReturn([
            'defaultModel' => 'gpt-4o-mini',
        ]);

        $dynamicProvider = $this->createMock(\NITSAN\NsT3AF\Contract\ExtensionSettingsDynamicDefaultsProviderInterface::class);
        $dynamicProvider->method('getExtensionKey')->willReturn('ns_t3ai');
        $dynamicProvider->method('getDynamicDefaults')->with(68)->willReturn([
            'enableAllInOneSeo' => '1',
        ]);

        $service = $this->createService(
            $registry,
            $repository,
            $schemaService,
            new ExtensionSettingsDynamicDefaultsRegistry([$dynamicProvider]),
        );

        self::assertSame([
            'defaultModel' => 'gpt-4o-mini',
            'enableAllInOneSeo' => '0',
        ], $service->getAll('ns_t3ai', 68));
    }

    public function testInitializeSiteSettingsPersistsAllManagedExtensionsOnFirstSave(): void
    {
        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('getManagedExtensionKeys')->willReturn(['ns_t3ai', 'ns_t3aa']);
        $registry->method('isManaged')->willReturn(true);

        $storedRows = [];
        $repository = $this->createMock(ExtensionSettingsRepository::class);
        $repository->method('findByExtensionKey')->willReturnCallback(
            static function (string $extensionKey, int $pid) use (&$storedRows): ?array {
                if ($pid !== 68 || !isset($storedRows[$extensionKey])) {
                    return null;
                }

                return $storedRows[$extensionKey];
            },
        );
        $repository->method('insert')->willReturnCallback(
            static function (string $extensionKey, int $pid) use (&$storedRows): void {
                $storedRows[$extensionKey] = [
                    'extension_key' => $extensionKey,
                    'settings_json' => '{}',
                ];
            },
        );
        $repository->method('updateSettingsJson')->willReturnCallback(
            static function (string $extensionKey, string $json, int $pid) use (&$storedRows): void {
                $storedRows[$extensionKey] = [
                    'extension_key' => $extensionKey,
                    'settings_json' => $json,
                ];
            },
        );

        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $schemaService->method('getDefaults')->willReturnMap([
            ['ns_t3ai', ['defaultModel' => 'gpt-4o-mini']],
            ['ns_t3aa', ['elevenlabsApiKey' => '', 'allowAiFileMeta' => '']],
        ]);

        $t3aiProvider = $this->createMock(\NITSAN\NsT3AF\Contract\ExtensionSettingsDynamicDefaultsProviderInterface::class);
        $t3aiProvider->method('getExtensionKey')->willReturn('ns_t3ai');
        $t3aiProvider->method('getDynamicDefaults')->willReturn(['enablePageSimple' => '1']);

        $service = $this->createService(
            $registry,
            $repository,
            $schemaService,
            new ExtensionSettingsDynamicDefaultsRegistry([$t3aiProvider]),
        );

        self::assertFalse($service->isSiteSettingsInitialized(68));
        $service->initializeSiteSettings(68, 'ns_t3ai', ['enablePageSimple' => '0']);
        self::assertTrue($service->isSiteSettingsInitialized(68));
    }
}
