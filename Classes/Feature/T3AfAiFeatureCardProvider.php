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

use NITSAN\NsT3AF\Contract\AiFeatureCardDescriptor;
use NITSAN\NsT3AF\Contract\AiFeatureCardProviderInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class T3AfAiFeatureCardProvider implements AiFeatureCardProviderInterface
{
    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('ns_t3af');
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3af';
    }

    public function getFeatureCards(): array
    {
        return [
            new AiFeatureCardDescriptor(
                id: 'universe-auth-api-translation',
                name: 'Access & Notifications',
                subtitle: 'Auth, alerts, translation default',
                extKey: 'ns_t3af',
                settingsScope: 'universe-auth-api-translation',
                icon: 'actions-envelope',
                iconBg: 'aiu-feature-card__icon--teal',
                iconColor: 'aiu-feature-card__glyph--teal',
                tags: ['universe', 'auth', 'notifications', 'translation', 'email'],
                description: 'HTTP basic authentication, API quota email alerts, and the default model for auto-translate operations.',
                sortPriority: 900,
            ),
        ];
    }
}
