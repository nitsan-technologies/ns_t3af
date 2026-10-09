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

use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

/**
 * Per-table allowlist for low-risk draft fields (meta description, SEO title, alt text).
 *
 * @internal
 */
final readonly class AgentLowRiskFieldMatrix
{
    /** @var array<string, list<string>> */
    private const SAFE_FIELDS = [
        'pages' => [
            'seo_title',
            'description',
            'abstract',
            'keywords',
            'og_title',
            'og_description',
        ],
        'sys_file_metadata' => ['alternative', 'description', 'title'],
    ];

    /**
     * Preview / DualMode content-param aliases → TCA column names used in SAFE_FIELDS.
     *
     * @var array<string, string>
     */
    private const FIELD_ALIASES = [
        'metatitle' => 'seo_title',
        'seo_title' => 'seo_title',
        'metadescription' => 'description',
        'ogtitle' => 'og_title',
        'og_title' => 'og_title',
        'ogdescription' => 'og_description',
        'og_description' => 'og_description',
        'alttext' => 'alternative',
        'alternative' => 'alternative',
    ];

    public function isSafeField(string $table, string $field): bool
    {
        if ($field === '' || str_starts_with($field, '_')) {
            return false;
        }

        $normalized = $this->normalizeFieldName($field);
        $allowed = self::SAFE_FIELDS[strtolower(trim($table))] ?? [];

        return in_array($normalized, $allowed, true);
    }

    /**
     * Keep preview field keys that map to low-risk columns for the target table.
     *
     * Namespaced batch keys (`123:metaTitle`) are evaluated on the field segment only.
     *
     * @param list<string> $fieldKeys
     * @return list<string>
     */
    public function filterSafePreviewFieldKeys(string $table, array $fieldKeys): array
    {
        $safe = [];
        foreach ($fieldKeys as $fieldKey) {
            if (!is_string($fieldKey) || $fieldKey === '') {
                continue;
            }
            $segment = str_contains($fieldKey, ':')
                ? (string) substr($fieldKey, (int) strrpos($fieldKey, ':') + 1)
                : $fieldKey;
            if ($this->isSafeField($table, $segment)) {
                $safe[] = $fieldKey;
            }
        }

        return $safe;
    }

    private function normalizeFieldName(string $field): string
    {
        $normalized = strtolower(trim($field));

        return self::FIELD_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * @param list<string> $fieldKeys
     * @return list<string>
     */
    public function filterSafeFieldKeys(ToolPlan $plan, array $fieldKeys): array
    {
        $safe = [];
        foreach ($plan->keptFields($fieldKeys) as $field) {
            if ($this->isSafeField($field->table, $field->field)) {
                $safe[] = $field->key;
            }
        }

        return $safe;
    }

    public function countSafeFields(ToolPlan $plan): int
    {
        $count = 0;
        foreach ($plan->fields as $field) {
            if ($this->isSafeField($field->table, $field->field)) {
                ++$count;
            }
        }

        return $count;
    }
}
