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

use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Access\ModuleAccessCatalog;
use NITSAN\NsT3AF\Contract\AiFeatureCardDescriptor;
use NITSAN\NsT3AF\Contract\AiFeatureCardProviderInterface;
use NITSAN\NsT3AF\Registry\AiAccessCatalogProviderRegistry;
use NITSAN\NsT3AF\Registry\AiFeatureCardProviderRegistry;
use NITSAN\NsT3AF\Service\WizardExtensionCatalogService;
use NITSAN\NsT3AF\Service\WizardFeatureToggleService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsDynamicDefaultsRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSchemaService;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\StubAccessCatalogProviders;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class WizardExtensionCatalogServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetLoadedExtensions([]);
        parent::tearDown();
    }

    public function testBuildCatalogIncludesWizardEligibleFeatureCards(): void
    {
        $this->resetLoadedExtensions(['ns_t3ai']);

        $provider = new class implements AiFeatureCardProviderInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function getExtensionKey(): string
            {
                return 'ns_t3ai';
            }

            public function getFeatureCards(): array
            {
                return [
                    new AiFeatureCardDescriptor(
                        id: 'ai-content',
                        name: 'AI Content',
                        subtitle: 'Content features',
                        extKey: 'ns_t3ai',
                        settingsScope: 'content',
                        icon: 'actions-file',
                        iconBg: 'bg',
                        iconColor: 'color',
                        tags: ['content'],
                        wizardEligible: true,
                        wizardToggleField: 'contentFeature',
                    ),
                ];
            }
        };

        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $schemaService->method('getDefaults')->willReturn(['contentFeature' => '1']);
        $schemaService->method('getConstantsByFieldName')->willReturn([]);

        $registry = $this->createMock(ExtensionSettingsRegistry::class);
        $registry->method('isManaged')->willReturnCallback(
            static fn(string $extensionKey): bool => $extensionKey === 'ns_t3ai',
        );

        $toggleService = new WizardFeatureToggleService(
            new AiFeatureCardProviderRegistry([$provider]),
            new ExtensionSettingsDynamicDefaultsRegistry([]),
        );

        $moduleAccessCatalog = new ModuleAccessCatalog(
            new ExtensionAvailability(),
            new AiAccessCatalogProviderRegistry(StubAccessCatalogProviders::all()),
        );

        $service = new WizardExtensionCatalogService(
            new AiFeatureCardProviderRegistry([$provider]),
            $moduleAccessCatalog,
            $registry,
            $schemaService,
            $toggleService,
            ProviderTestStubs::emptySuiteBadgeRegistry(),
        );

        $catalog = $service->buildCatalog();

        self::assertTrue($catalog['hasToggles']);
        self::assertNotSame([], $catalog['groups']);
        self::assertArrayHasKey('ns_t3ai', $catalog['defaults']);
        self::assertTrue($catalog['defaults']['ns_t3ai']['contentFeature']);
        self::assertArrayNotHasKey('general', $catalog['groups'][0]);
    }

    public function testHasEligibleExtensionsIsFalseWithoutWizardCards(): void
    {
        $schemaService = $this->createMock(ExtensionSettingsSchemaService::class);
        $registry = $this->createMock(ExtensionSettingsRegistry::class);

        $toggleService = new WizardFeatureToggleService(
            new AiFeatureCardProviderRegistry([]),
            new ExtensionSettingsDynamicDefaultsRegistry([]),
        );

        $moduleAccessCatalog = new ModuleAccessCatalog(
            new ExtensionAvailability(),
            new AiAccessCatalogProviderRegistry(StubAccessCatalogProviders::all()),
        );

        $service = new WizardExtensionCatalogService(
            new AiFeatureCardProviderRegistry([]),
            $moduleAccessCatalog,
            $registry,
            $schemaService,
            $toggleService,
            ProviderTestStubs::emptySuiteBadgeRegistry(),
        );

        self::assertFalse($service->hasEligibleExtensions());
    }

    /**
     * @param list<string> $extensionKeys
     */
    private function resetLoadedExtensions(array $extensionKeys): void
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('isPackageActive')
            ->willReturnCallback(static fn(string $key): bool => in_array($key, $extensionKeys, true));

        $property = new \ReflectionProperty(ExtensionManagementUtility::class, 'packageManager');
        $property->setValue(null, $packageManager);
    }
}
