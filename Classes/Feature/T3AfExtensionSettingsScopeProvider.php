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

namespace NITSAN\NsT3AF\Feature;

use NITSAN\NsT3AF\Contract\ExtensionSettingsScopeMessagesTrait;
use NITSAN\NsT3AF\Contract\ExtensionSettingsScopeProviderInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class T3AfExtensionSettingsScopeProvider implements ExtensionSettingsScopeProviderInterface
{
    use ExtensionSettingsScopeMessagesTrait;
    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('ns_t3af');
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3af';
    }

    public function getAllowedScopes(): array
    {
        return [
            'universe-auth-api-translation',
            'deepl',
            'google',
            'basic authentication',
            'api notifications',
            'openai usage api',
            't3planet credits',
            'mcp server',
        ];
    }

    public function getCompositeScopeCategories(): array
    {
        return [
            'universe-auth-api-translation' => [
                'basic authentication',
                'api notifications',
            ],
        ];
    }

    public function getPaletteScopes(): array
    {
        return [];
    }

    public function getFieldFilterScopes(): array
    {
        return [];
    }

    public function getSaveSuccessMessageKey(): string
    {
        return 'module.aiFeatures.saveSuccessMessageUniverse';
    }
}
