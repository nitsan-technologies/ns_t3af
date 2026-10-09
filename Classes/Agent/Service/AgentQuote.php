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

namespace NITSAN\NsT3AF\Agent\Service;

/**
 * Quotation marks in the editor's language: „Titel“ in German, “Title” everywhere else.
 *
 * @internal
 */
final class AgentQuote
{
    public static function wrap(string $text): string
    {
        $language = $GLOBALS['LANG'] ?? null;
        $key = '';
        if (is_object($language)) {
            if (method_exists($language, 'getLocale')) {
                $locale = $language->getLocale();
                $key = is_object($locale) && method_exists($locale, 'getLanguageCode') ? (string) $locale->getLanguageCode() : '';
            } elseif (property_exists($language, 'lang')) {
                $key = (string) $language->lang;
            }
        }
        $key = strtolower($key);

        return str_starts_with($key, 'de') ? '„' . $text . '“' : '“' . $text . '”';
    }
}
