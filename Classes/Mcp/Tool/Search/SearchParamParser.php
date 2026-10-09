<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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

namespace NITSAN\NsT3AF\Mcp\Tool\Search;

/**
 * Reads the `search` parameter of record_search / record_count.
 *
 * The contract is a JSON object ({"title":"hello"}), but models also send an empty string,
 * single-quoted JSON, or "field=value" / "field: value" pairs. Those are accepted instead of
 * failing with a bare JSON "Syntax error". Plain text ("AI vs Human") is searched as a LIKE match on
 * the table's label field. Anything else gets an error that shows the expected shape.
 *
 * @internal
 */
final class SearchParamParser
{
    /**
     * @param string|null $labelField field used for a plain-text search (the table's TCA label)
     * @param list<string>|null $allowedFields when given, "field=value" pairs count only for these fields
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when the value cannot be understood
     */
    public static function parse(string $search, ?string $labelField = null, ?array $allowedFields = null): array
    {
        $trimmed = trim($search);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // {'title': 'hello'}
        if ($trimmed[0] === '{' && str_contains($trimmed, "'")) {
            $decoded = json_decode(str_replace("'", '"', $trimmed), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // title=hello, pid=5   |   title: hello AND uid: 5
        if ($trimmed[0] !== '{' && $trimmed[0] !== '[') {
            $conditions = [];
            foreach (preg_split('/\s*(?:,|;|\bAND\b)\s*/i', $trimmed) ?: [] as $part) {
                if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:=|:)\s*(.+?)\s*$/s', $part, $m) === 1) {
                    $conditions[$m[1]] = trim($m[2], "\"' ");
                }
            }
            if ($conditions !== [] && ($allowedFields === null || array_intersect(array_keys($conditions), $allowedFields) !== [])) {
                return $conditions;
            }

            if ($labelField !== null && $labelField !== '') {
                return [$labelField => $trimmed];
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'search must be a JSON object such as {"title":"hello"} (received: %s).',
            mb_substr($trimmed, 0, 80),
        ));
    }
}
