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

namespace NITSAN\NsT3AF\Provider\Model;

use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Canonical model ids from vendor `/models` list responses and provider rows.
 *
 * Google Gemini advertises resource names (`models/gemini-3.5-flash`) while the
 * Symfony AI {@see \Symfony\AI\Platform\Bridge\Gemini\ModelCatalog} keys omit
 * the prefix. Normalization is scoped to {@see Provider::normalizeAdapterType()}
 * `symfony.gemini` only so other adapters keep raw ids (Azure deployments, etc.).
 *
 * @internal
 */
final class VendorModelIdNormalizer
{
    private const GEMINI_ADAPTER = 'symfony.gemini';

    public static function canonicalize(string $rawId, string $adapterType): string
    {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return '';
        }

        $adapterType = Provider::normalizeAdapterType($adapterType);
        if ($adapterType !== self::GEMINI_ADAPTER) {
            return $rawId;
        }

        if (str_starts_with($rawId, 'models/')) {
            return substr($rawId, strlen('models/'));
        }

        return $rawId;
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function idFromApiItem(array $item, string $adapterType): ?string
    {
        $adapterType = Provider::normalizeAdapterType($adapterType);
        if ($adapterType === self::GEMINI_ADAPTER) {
            $base = $item['baseModelId'] ?? null;
            if (is_string($base) && trim($base) !== '') {
                return self::canonicalize(trim($base), $adapterType);
            }
        }

        foreach (['id', 'name', 'model'] as $key) {
            $candidate = $item[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return self::canonicalize(trim($candidate), $adapterType);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function idsFromModelsListJson(string $body, string $adapterType): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }
        $list = $decoded['data'] ?? $decoded['models'] ?? null;
        if (!is_array($list)) {
            return [];
        }

        $ids = [];
        foreach ($list as $item) {
            if (is_string($item) && trim($item) !== '') {
                $ids[] = self::canonicalize(trim($item), $adapterType);
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $id = self::idFromApiItem($item, $adapterType);
            if ($id !== null && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
