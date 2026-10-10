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
 * Which site language a glossary save may use.
 *
 * Several target languages and no named language must be asked. A named language
 * is used even when the model sent a different id. One target language is left alone.
 *
 * @internal
 */
final class GlossarySaveLanguage
{
    /**
     * @param list<array<string, mixed>> $siteLanguages
     * @return array{languageUid?: int, options?: list<string>}
     */
    public static function resolve(string $request, array $siteLanguages): array
    {
        $languages = [];
        foreach ($siteLanguages as $language) {
            $id = (int) ($language['id'] ?? 0);
            $title = trim((string) ($language['title'] ?? ''));
            if ($id <= 0 || $title === '') {
                continue;
            }
            $languages[$id] = $title;
        }
        if (count($languages) <= 1) {
            return [];
        }

        $named = [];
        foreach ($languages as $id => $title) {
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($title, '/') . '(?![\p{L}\p{N}_])/iu';
            if (preg_match($pattern, $request) === 1) {
                $named[$id] = $title;
            }
        }
        if (count($named) === 1) {
            return ['languageUid' => (int) array_key_first($named)];
        }

        return ['options' => array_values($languages)];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public static function siteLanguages(array $context): array
    {
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $languages = $details['siteLanguages'] ?? null;

        return is_array($languages) ? array_values(array_filter($languages, 'is_array')) : [];
    }
}
