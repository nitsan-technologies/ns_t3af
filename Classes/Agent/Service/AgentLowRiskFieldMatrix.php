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
        'pages' => ['description', 'abstract', 'keywords'],
        'sys_file_metadata' => ['alternative', 'description', 'title'],
    ];

    public function isSafeField(string $table, string $field): bool
    {
        if ($field === '' || str_starts_with($field, '_')) {
            return false;
        }

        $allowed = self::SAFE_FIELDS[strtolower(trim($table))] ?? [];

        return in_array(strtolower(trim($field)), $allowed, true);
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
