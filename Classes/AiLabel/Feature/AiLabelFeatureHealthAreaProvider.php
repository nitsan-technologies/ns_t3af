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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\AiLabel\Feature;

use NITSAN\NsT3AF\Contract\FeatureHealthAreaDescriptor;
use NITSAN\NsT3AF\Contract\FeatureHealthAreaProviderInterface;

final class AiLabelFeatureHealthAreaProvider implements FeatureHealthAreaProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getHealthAreas(): array
    {
        return [
            new FeatureHealthAreaDescriptor(
                id: 'ai_label',
                label: 'AI Label',
                extensionKey: 'ns_t3af',
                iconIdentifier: 'actions-tag',
                requestLogPrefixes: ['ailabel'],
            ),
        ];
    }
}
