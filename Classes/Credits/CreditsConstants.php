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

namespace NITSAN\NsT3AF\Credits;

/**
 * Shared constants for T3Planet Credits client integration.
 *
 * @internal
 */
final class CreditsConstants
{
    public const DEFAULT_API_BASE_URL = 'https://composer.t3planet.cloud';

    public const STAGING_API_BASE_URL = 'https://composer.thebetaspace.com';

    public const LOCAL_DDEV_API_BASE_URL = 'https://composer.ddev.site';

    public const RUNTIME_SETTING_UID = 1;

    public const PRODUCT_CATALOG_UID = 1;
}
