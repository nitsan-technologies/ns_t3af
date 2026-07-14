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

namespace NITSAN\NsT3AF\Service;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves {@see SiteStorageContext} in Composer and classic (non-Composer) installs.
 *
 * Classic installs use the bundled phar and may call helpers before a public DI
 * service is available; this factory falls back to explicit constructor wiring.
 */
final class SiteStorageContextFactory
{
    public static function get(): SiteStorageContext
    {
        try {
            return GeneralUtility::makeInstance(SiteStorageContext::class);
        } catch (\Throwable) {
            return GeneralUtility::makeInstance(
                SiteStorageContext::class,
                GeneralUtility::makeInstance(SiteFinder::class),
            );
        }
    }
}
