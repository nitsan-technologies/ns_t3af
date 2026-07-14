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

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Icon registry for ns_t3af.
 *
 * Only register icons that ship inside this extension. Core icons (every
 * `actions-*`, `status-*`, `content-*`, `apps-*` identifier in the TYPO3
 * backend icon library) are already registered by `typo3/cms-core` and can be
 * referenced directly from Fluid via `<core:icon identifier="actions-open" />`
 * — re-registering them under a domain prefix is duplication.
 *
 * Add a new entry here only when the source SVG lives under
 * `Resources/Public/Icons/` of this extension.
 */
$isV14OrHigher = (new Typo3Version())->getMajorVersion() >= 14;

return [
    // TYPO3 v14+ backend module menu (currentColor — adapts to Fresh/Modern/Classic)
    'ns-t3af-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ns_t3af/Resources/Public/Icons/ModuleV14.svg',
    ],
    // TYPO3 v12/v13 backend module menu (purple badge tile)
    'ns-t3af-module13' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ns_t3af/Resources/Public/Icons/AI_Uni_Icon_v13.svg',
    ],
    // TYPO3 v14+ AI Foundation submodule (eye — currentColor + accent)
    'ns-t3af-foundation-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ns_t3af/Resources/Public/Icons/FoundationModuleV14.svg',
    ],
    // TYPO3 v12/v13 AI Foundation submodule
    'ns-t3af-foundation-module13' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ns_t3af/Resources/Public/Icons/Extension.svg',
    ],
    'ns-t3af-header-logo' => [
        'provider' => SvgIconProvider::class,
        'source' => $isV14OrHigher
            ? 'EXT:ns_t3af/Resources/Public/Icons/HeaderLogoV14.svg'
            : 'EXT:ns_t3af/Resources/Public/Icons/HeaderLogo.svg',
    ],
];
