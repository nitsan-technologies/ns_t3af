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

namespace NITSAN\NsT3AF\Bootstrap;

use NITSAN\NsT3AF\Registry\AiAccessCatalogProviderRegistry;

/**
 * Fills BE customPermOptions['T3Ai'] items from AiAccessCatalogProviderInterface.
 *
 * Parent ext_localconf only declares the header + empty items; child extensions
 * own feature bits via their access catalog providers.
 */
final class AiAccessCustomOptionsBootstrap
{
    public function __construct(
        private readonly AiAccessCatalogProviderRegistry $accessProviderRegistry,
    ) {}

    public function register(): void
    {
        if (!isset($GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['T3Ai'])
            || !is_array($GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['T3Ai'])
        ) {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['T3Ai'] = [
                'header' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:access.t3ai.header',
                'items' => [],
            ];
        }

        $items = $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['T3Ai']['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        foreach ($this->accessProviderRegistry->getFeaturePermissions() as $descriptor) {
            $permBase = $descriptor->permBase;
            if ($permBase === '' || isset($items[$permBase])) {
                continue;
            }
            $items[$permBase] = [$descriptor->label, 'actions-key'];
        }

        $GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['T3Ai']['items'] = $items;
    }
}
