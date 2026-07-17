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

namespace NITSAN\NsT3AF\Tests\Unit\Access\Support;

use NITSAN\NsT3AF\Access\DefaultGroupConfigFactory;
use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Access\FeaturePermissionCatalog;
use NITSAN\NsT3AF\Access\GroupConfigDeserializer;
use NITSAN\NsT3AF\Access\GroupConfigNormalizer;
use NITSAN\NsT3AF\Access\GroupConfigSerializer;
use NITSAN\NsT3AF\Access\GroupPresetRegistry;
use NITSAN\NsT3AF\Access\ModuleAccessCatalog;
use NITSAN\NsT3AF\Access\RecordPermissionCatalog;
use NITSAN\NsT3AF\Access\WizardBootstrapFactory;
use NITSAN\NsT3AF\Registry\AiAccessCatalogProviderRegistry;
use NITSAN\NsT3AF\Registry\FeatureAccessBindingRegistry;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Mocks {@see ExtensionManagementUtility::isLoaded()} for access-catalog unit tests.
 */
trait LoadedExtensionsTestTrait
{
    /**
     * @param list<string> $extensionKeys
     */
    protected function mockLoadedExtensions(array $extensionKeys): void
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('isPackageActive')
            ->willReturnCallback(static fn(string $key): bool => in_array($key, $extensionKeys, true));

        $property = new \ReflectionProperty(ExtensionManagementUtility::class, 'packageManager');
        $property->setValue(null, $packageManager);
    }

    protected function mockAllCatalogExtensionsLoaded(): void
    {
        $this->mockLoadedExtensions([
            'ns_t3af',
            'ns_t3ai',
            'ns_t3aa',
            'ns_t3cs',
            'ns_t3ac',
            'ns_t3as',
        ]);
    }

    protected function resetLoadedExtensions(): void
    {
        $this->mockLoadedExtensions([]);
    }

    protected function createAccessProviderRegistry(): AiAccessCatalogProviderRegistry
    {
        return new AiAccessCatalogProviderRegistry(StubAccessCatalogProviders::all());
    }

    protected function createFeatureAccessBindingRegistry(): FeatureAccessBindingRegistry
    {
        return new FeatureAccessBindingRegistry($this->createAccessProviderRegistry());
    }

    protected function createModuleAccessCatalog(): ModuleAccessCatalog
    {
        return new ModuleAccessCatalog(
            new ExtensionAvailability(),
            $this->createAccessProviderRegistry(),
        );
    }

    protected function createFeaturePermissionCatalog(): FeaturePermissionCatalog
    {
        return new FeaturePermissionCatalog(
            new ExtensionAvailability(),
            $this->createAccessProviderRegistry(),
        );
    }

    protected function createRecordPermissionCatalog(): RecordPermissionCatalog
    {
        return new RecordPermissionCatalog(
            new ExtensionAvailability(),
            $this->createAccessProviderRegistry(),
        );
    }

    protected function createWizardBootstrapFactory(): WizardBootstrapFactory
    {
        return new WizardBootstrapFactory(
            $this->createModuleAccessCatalog(),
            $this->createFeaturePermissionCatalog(),
            $this->createRecordPermissionCatalog(),
        );
    }

    protected function createGroupConfigNormalizer(): GroupConfigNormalizer
    {
        return new GroupConfigNormalizer(
            $this->createFeaturePermissionCatalog(),
            $this->createRecordPermissionCatalog(),
            $this->createWizardBootstrapFactory(),
            $this->createFeatureAccessBindingRegistry(),
        );
    }

    protected function createGroupConfigDeserializer(): GroupConfigDeserializer
    {
        return new GroupConfigDeserializer(
            $this->createModuleAccessCatalog(),
            $this->createFeaturePermissionCatalog(),
            $this->createRecordPermissionCatalog(),
            $this->createWizardBootstrapFactory(),
            $this->createFeatureAccessBindingRegistry(),
        );
    }

    protected function defaultGroupModules(): array
    {
        return $this->createWizardBootstrapFactory()->defaultModules();
    }

    protected function defaultGroupFeatures(): array
    {
        return $this->createWizardBootstrapFactory()->defaultFeatures();
    }

    protected function defaultGroupRecords(): array
    {
        return $this->createWizardBootstrapFactory()->defaultRecords();
    }

    protected function createGroupPresetRegistry(): GroupPresetRegistry
    {
        return new GroupPresetRegistry(
            $this->createRecordPermissionCatalog(),
            $this->createModuleAccessCatalog(),
            new DefaultGroupConfigFactory($this->createWizardBootstrapFactory()),
            StubGroupPresetContributors::all(),
        );
    }

    /**
     * @return list<\NITSAN\NsT3AF\Contract\LegacyCustomOptionExpanderInterface>
     */
    protected function createLegacyCustomOptionExpanders(): array
    {
        $expanders = [];
        if (class_exists(\NITSAN\NsT3Ai\Access\T3AiLegacyCustomOptionExpander::class)) {
            $expanders[] = new \NITSAN\NsT3Ai\Access\T3AiLegacyCustomOptionExpander();
        }
        if (class_exists(\NITSAN\NsT3Aa\Access\T3AaLegacyCustomOptionExpander::class)) {
            $expanders[] = new \NITSAN\NsT3Aa\Access\T3AaLegacyCustomOptionExpander();
        }

        return $expanders;
    }

    protected function createGroupConfigSerializer(): GroupConfigSerializer
    {
        return new GroupConfigSerializer(
            $this->createModuleAccessCatalog(),
            $this->createFeaturePermissionCatalog(),
            $this->createRecordPermissionCatalog(),
            $this->createFeatureAccessBindingRegistry(),
            $this->createLegacyCustomOptionExpanders(),
        );
    }
}
