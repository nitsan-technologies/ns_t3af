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
 * A request to create a page directly before another page, taken from the editor's words.
 *
 * @internal
 */
final class PageCreateBefore
{
    public static function isCreateBefore(string $query): bool
    {
        $query = trim($query);
        if ($query === '' || preg_match('/\b(move|moves|moved|moving|verschieb\w*)\b/iu', $query) === 1) {
            return false;
        }
        if (preg_match('/\b(content|element|text|image|header|tt_content)\b/iu', $query) === 1) {
            return false;
        }
        if (preg_match('/\b(create|new|anleg\w*|neue[rnms]?)\b/iu', $query) !== 1) {
            return false;
        }
        if (preg_match('/\b(after|nach)\b/iu', $query) === 1) {
            return false;
        }

        return preg_match('/\b(before|vor)\b/iu', $query) === 1;
    }

    /**
     * @return array{title: string, uid: int}
     */
    public static function target(string $query): array
    {
        if (preg_match('/\b(?:before|vor)\s+(.+)$/iu', $query, $match) !== 1) {
            return ['title' => '', 'uid' => 0];
        }

        $rest = trim((string) preg_replace('/\s*\(.*$/', '', $match[1]));
        $rest = trim((string) preg_replace('/\s+(?:named|called|titled|at\s+the\s+end)\b.*$/iu', '', $rest));
        $uid = 0;
        if (preg_match('/\[(\d+)\]\s*$/', $rest, $uidMatch) === 1) {
            $uid = (int) $uidMatch[1];
            $rest = trim((string) preg_replace('/\s*\[\d+\]\s*$/', '', $rest));
        }

        return ['title' => trim($rest, " \t\"“”'"), 'uid' => $uid];
    }
}
