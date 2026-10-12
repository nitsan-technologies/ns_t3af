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
 * Page-structure suggestion cards store JSON for Apply and show a bullet outline.
 * Custom edits may be either JSON or that outline.
 *
 * @internal
 */
final class AgentPageStructureOutline
{
    /**
     * @return array{structure: string, fellBackToDraft: bool}
     */
    public static function resolveEdit(string $edit, string $draftJson): array
    {
        $edit = trim($edit);
        if ($edit === '') {
            return ['structure' => $draftJson, 'fellBackToDraft' => true];
        }
        try {
            $decoded = json_decode($edit, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return ['structure' => $edit, 'fellBackToDraft' => false];
            }
        } catch (\JsonException) {
            // Try a bullet / indented outline next.
        }
        $fromOutline = self::parseOutline($edit);
        if ($fromOutline !== null) {
            return [
                'structure' => json_encode($fromOutline, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'fellBackToDraft' => false,
            ];
        }

        return ['structure' => $draftJson, 'fellBackToDraft' => true];
    }

    /**
     * "• About Us" / "- Products" with 2-space (or tab) indent → {title, children}.
     *
     * @return array{title: string, children: list<array<string, mixed>>}|null
     */
    public static function parseOutline(string $outline): ?array
    {
        /** @var list<array{title: string, children: list<array<string, mixed>>}> $roots */
        $roots = [];
        /** @var list<array{title: string, children: list<array<string, mixed>>}> $stack */
        $stack = [];
        foreach (preg_split('/\R/u', $outline) ?: [] as $line) {
            if (!preg_match('/^(?P<indent>[ \t]*)(?:[•\-\*]|\d+[.)])\s+(?P<title>\S.*)$/u', $line, $match)) {
                if (trim($line) === '') {
                    continue;
                }

                return null;
            }
            $indent = str_replace("\t", '  ', $match['indent']);
            if (strlen($indent) % 2 !== 0) {
                return null;
            }
            $title = trim($match['title']);
            if ($title === '') {
                return null;
            }
            $depth = (int) (strlen($indent) / 2);
            $node = ['title' => $title, 'children' => []];
            if ($depth === 0) {
                $roots[] = $node;
                $stack = [&$roots[array_key_last($roots)]];
                continue;
            }
            if ($stack === [] || $depth > count($stack)) {
                return null;
            }
            while (count($stack) > $depth) {
                array_pop($stack);
            }
            $parent = &$stack[array_key_last($stack)];
            $parent['children'][] = $node;
            $stack[] = &$parent['children'][array_key_last($parent['children'])];
            unset($parent);
        }
        if ($roots === []) {
            return null;
        }

        return ['title' => '', 'children' => $roots];
    }
}
