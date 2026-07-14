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

namespace NITSAN\NsT3AF\Mcp\Tool\Search;

/** @internal */
final class SearchConditionParser
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowedFields
     * @return array<string, array{operator: string, value: string}>
     */
    public static function fromArray(array $data, array $allowedFields): array
    {
        $conditions = [];
        foreach ($data as $field => $value) {
            if (!in_array((string) $field, $allowedFields, true)) {
                continue;
            }

            $conditions[(string) $field] = self::parseCondition($value);
        }

        return $conditions;
    }

    /** @return array{operator: string, value: string} */
    private static function parseCondition(mixed $value): array
    {
        if (is_array($value) && isset($value['op'])) {
            $op = $value['op'];
            $val = $value['value'] ?? '';

            return [
                'operator' => is_string($op) ? $op : '',
                'value' => is_string($val) || is_int($val) || is_float($val) ? (string) $val : '',
            ];
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return ['operator' => 'like', 'value' => (string) $value];
        }

        return ['operator' => 'like', 'value' => ''];
    }
}
