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
 * A language the editor names that the site does not have ("Japanese", "Klingon").
 *
 * With one target language a model fills in that language for any name it is given, and the card then shows
 * "German" for a request in Japanese. The editor has to be told instead.
 *
 * @internal
 */
final class RequestedLanguage
{
    /** Tools whose language argument comes from a language the editor names. */
    private const LANGUAGE_TOOLS = [
        't3ai_glossary_save',
        't3ai_translate_page',
        't3ai_translate_content',
        't3ai_translate_news',
        't3ai_record_translate',
        't3ai_mass_translation_queue_add',
    ];

    /**
     * Alias groups (English and German names, ISO code). The first entry is the English name.
     *
     * @var list<list<string>>
     */
    private const LANGUAGES = [
        ['English', 'Englisch'],
        ['German', 'Deutsch'],
        ['French', 'Französisch', 'Francais', 'Français'],
        ['Spanish', 'Spanisch', 'Español', 'Espanol'],
        ['Italian', 'Italienisch', 'Italiano'],
        ['Portuguese', 'Portugiesisch', 'Português'],
        ['Dutch', 'Niederländisch', 'Nederlands'],
        ['Polish', 'Polnisch', 'Polski'],
        ['Russian', 'Russisch'],
        ['Ukrainian', 'Ukrainisch'],
        ['Czech', 'Tschechisch'],
        ['Slovak', 'Slowakisch'],
        ['Hungarian', 'Ungarisch'],
        ['Romanian', 'Rumänisch'],
        ['Bulgarian', 'Bulgarisch'],
        ['Greek', 'Griechisch'],
        ['Turkish', 'Türkisch'],
        ['Swedish', 'Schwedisch'],
        ['Danish', 'Dänisch'],
        ['Norwegian', 'Norwegisch'],
        ['Finnish', 'Finnisch'],
        ['Japanese', 'Japanisch'],
        ['Chinese', 'Chinesisch', 'Mandarin'],
        ['Korean', 'Koreanisch'],
        ['Arabic', 'Arabisch'],
        ['Hebrew', 'Hebräisch'],
        ['Hindi'],
        ['Thai'],
        ['Vietnamese', 'Vietnamesisch'],
        ['Indonesian', 'Indonesisch'],
        ['Latin', 'Latein'],
        ['Klingon', 'Klingonisch'],
    ];

    public static function isLanguageTool(string $toolName): bool
    {
        return in_array(strtolower(trim($toolName)), self::LANGUAGE_TOOLS, true);
    }

    /**
     * The first named language the site does not have, or null. Only a request that names no site language
     * counts: "French fries in German" is about German.
     *
     * @param list<array<string, mixed>> $siteLanguages entries with "title"
     */
    public static function unavailable(string $request, array $siteLanguages): ?string
    {
        $available = [];
        foreach ($siteLanguages as $language) {
            $title = trim((string) ($language['title'] ?? ''));
            if ($title !== '') {
                $available[self::canonical($title)] = true;
            }
        }
        if ($available === []) {
            return null;
        }

        // Quoted text is the term or the content, not a language to pick.
        $text = (string) preg_replace('/(["“”„\'‚‘’«»]).{0,200}?\1/u', ' ', $request);
        $text = (string) preg_replace('/[“„«][^”“»]{0,200}[”“»]/u', ' ', $text);

        $missing = null;
        foreach (self::LANGUAGES as $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($alias, '/') . '(?:e[nrsm]?)?(?![\p{L}\p{N}_])/iu', $text) !== 1) {
                    continue;
                }
                if (isset($available[self::canonical($aliases[0])])) {
                    return null;
                }
                $missing ??= $aliases[0];
                break;
            }
        }

        return $missing;
    }

    /**
     * @param list<array<string, mixed>> $siteLanguages
     */
    public static function titles(array $siteLanguages): string
    {
        $titles = [];
        foreach ($siteLanguages as $language) {
            $title = trim((string) ($language['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return implode(', ', array_values(array_unique($titles)));
    }

    private static function canonical(string $name): string
    {
        $needle = mb_strtolower(trim($name));
        foreach (self::LANGUAGES as $aliases) {
            foreach ($aliases as $alias) {
                if (mb_strtolower($alias) === $needle) {
                    return mb_strtolower($aliases[0]);
                }
            }
        }

        return $needle;
    }
}
