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

namespace NITSAN\NsT3AF\Settings;

use NITSAN\NsT3AF\Registry\ExtensionSettingsScopeRegistry;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * @internal
 */
class ExtensionSettingsRegistry
{
    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly ExtensionSettingsScopeRegistry $scopeRegistry,
    ) {}

    /**
     * @return list<string>
     */
    public function getManagedExtensionKeys(): array
    {
        $keys = [];
        foreach ($this->scopeRegistry->getManagedExtensionKeys() as $extensionKey) {
            if ($this->isManaged($extensionKey)) {
                $keys[] = $extensionKey;
            }
        }

        return $keys;
    }

    public function isManaged(string $extensionKey): bool
    {
        return ExtensionManagementUtility::isLoaded($extensionKey)
            && $this->scopeRegistry->supportsExtension($extensionKey)
            && $this->getSchemaPath($extensionKey) !== null;
    }

    public function getSchemaPath(string $extensionKey): ?string
    {
        if (!ExtensionManagementUtility::isLoaded($extensionKey)) {
            return null;
        }

        try {
            $path = $this->packageManager->getPackage($extensionKey)->getPackagePath()
                . 'Configuration/ExtensionSettings/schema.php';
        } catch (\Throwable) {
            return null;
        }

        return is_file($path) ? $path : null;
    }
}
