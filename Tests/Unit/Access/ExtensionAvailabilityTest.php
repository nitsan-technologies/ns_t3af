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

namespace NITSAN\NsT3AF\Tests\Unit\Access;

use NITSAN\NsT3AF\Access\ExtensionAvailability;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class ExtensionAvailabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetLoadedExtensions([]);
        parent::tearDown();
    }

    public function testEmbeddingModelConfigurationRequiresT3CsT3AsOrT3Ac(): void
    {
        $availability = new ExtensionAvailability(ProviderTestStubs::embeddingCapabilityProviders());

        $this->resetLoadedExtensions([]);
        self::assertFalse($availability->isEmbeddingModelConfigurationAvailable());

        $this->resetLoadedExtensions(['ns_t3cs']);
        self::assertTrue($availability->isEmbeddingModelConfigurationAvailable());

        $this->resetLoadedExtensions(['ns_t3as']);
        self::assertTrue($availability->isEmbeddingModelConfigurationAvailable());

        $this->resetLoadedExtensions(['ns_t3ac']);
        self::assertTrue($availability->isEmbeddingModelConfigurationAvailable());

        $this->resetLoadedExtensions(['ns_t3ai']);
        self::assertFalse($availability->isEmbeddingModelConfigurationAvailable());
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
