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

namespace NITSAN\NsT3AF\Utility;

/**
 * Shared extension-key constants and optional child-product EXTCONF helpers.
 *
 * AI Foundation no longer gates the backend module on an OSS licence key.
 * Optional {@see \NITSAN\NsT3AF\Credits\Service\LicenseContactResolver} still
 * reads ns_license rows when EXT:ns_license is present (Credits contact only).
 */
final class LicenseUtility
{
    public const EXTENSION_KEY = 'ns_t3af';

    public const LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY = 'licenseDependentExtensions';

    public const REASON_OK = 'ok';

    /** @deprecated Kept for BC; module gate removed — always OK. */
    public const REASON_NS_LICENSE_MISSING = 'ns_license_missing';

    /** @deprecated Kept for BC; module gate removed — always OK. */
    public const REASON_NO_VALID_KEY = 'no_valid_key';

    /**
     * @return array{valid: bool, reason: string}
     */
    public static function getModuleLicenseStatus(): array
    {
        return [
            'valid' => true,
            'reason' => self::REASON_OK,
        ];
    }

    public static function checkLicenseForModules(): bool
    {
        return true;
    }

    /**
     * ViewHelper convention: true means hide restricted UI.
     */
    public static function checkLicenseForViewHelper(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public static function resolveLicenseDependentExtensionKeys(): array
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af'][self::LICENSE_DEPENDENT_EXTENSIONS_EXTCONF_KEY] ?? [];
        if (!is_array($configured)) {
            return [];
        }

        $keys = [];
        foreach ($configured as $extensionKey) {
            if (!is_string($extensionKey)) {
                continue;
            }
            $extensionKey = trim($extensionKey);
            if ($extensionKey !== '') {
                $keys[] = $extensionKey;
            }
        }

        return array_values(array_unique($keys));
    }
}
