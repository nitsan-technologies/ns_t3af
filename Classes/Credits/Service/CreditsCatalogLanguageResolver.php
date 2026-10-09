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

namespace NITSAN\NsT3AF\Credits\Service;

/**
 * Maps the backend user UI language to Products/Features {@code language} ({@code en}|{@code de}).
 *
 * @internal
 */
final class CreditsCatalogLanguageResolver
{
    public const EN = 'en';

    public const DE = 'de';

    /**
     * @return self::EN|self::DE
     */
    public function resolve(): string
    {
        $raw = $this->rawBackendLanguage();

        return self::normalize($raw);
    }

    /**
     * Prefer {@see BackendUserAuthentication::$user}{@code lang} (be_users.lang).
     * Fall back to uc['lang'] / LanguageService — uc alone is often empty.
     */
    private function rawBackendLanguage(): string
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (is_object($beUser)) {
            if (isset($beUser->user) && is_array($beUser->user)) {
                $fromUser = trim((string) ($beUser->user['lang'] ?? ''));
                if ($fromUser !== '') {
                    return $fromUser;
                }
            }
            if (isset($beUser->uc) && is_array($beUser->uc)) {
                $fromUc = trim((string) ($beUser->uc['lang'] ?? ''));
                if ($fromUc !== '') {
                    return $fromUc;
                }
            }
        }

        $langService = $GLOBALS['LANG'] ?? null;
        if (is_object($langService) && isset($langService->lang)) {
            return trim((string) $langService->lang);
        }

        return self::EN;
    }

    /**
     * Normalize any client/server value to the supported catalog language set.
     * TYPO3 uses {@code default} for English.
     *
     * @return self::EN|self::DE
     */
    public static function normalize(string $language): string
    {
        $full = strtolower(trim($language));
        if ($full === '' || $full === 'default' || str_starts_with($full, 'default')) {
            return self::EN;
        }

        $code = substr($full, 0, 2);

        return $code === self::DE ? self::DE : self::EN;
    }
}
