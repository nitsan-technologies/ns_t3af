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

namespace NITSAN\NsT3AF\Mcp\Tool\Search;

/** @internal */
final class SearchSortResolver
{
    /**
     * @param list<string> $allowedFields
     * @return array{0: ?string, 1: string}
     */
    public static function resolve(string $orderBy, string $orderDirection, array $allowedFields): array
    {
        $resolvedOrderBy = null;
        if ($orderBy !== '') {
            if (!in_array($orderBy, $allowedFields, true)) {
                throw new \InvalidArgumentException('Invalid orderBy field: ' . $orderBy, 1790600007);
            }
            $resolvedOrderBy = $orderBy;
        }

        $orderDirection = strtoupper(trim($orderDirection));
        if ($orderDirection === '') {
            $orderDirection = 'ASC';
        }
        if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('orderDirection must be ASC or DESC.', 1790600008);
        }

        return [$resolvedOrderBy, $orderDirection];
    }
}
