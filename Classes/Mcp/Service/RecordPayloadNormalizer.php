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

namespace NITSAN\NsT3AF\Mcp\Service;

/**
 * Turns a raw field payload from an MCP client into something DataHandler can take.
 *
 * Shared by every MCP tool that writes records, so a field behaves the same wherever it is sent:
 * which fields are writable, how file references (`[{"uid_local": 5}]`) and relation uid lists
 * (`[8, 12]` or `"8,12"`) are shaped, and how an ignored field is explained back to the client.
 *
 * Extracted unchanged from WriteTableTool; it does not talk to the database.
 */
readonly class RecordPayloadNormalizer
{
    /** @var list<string> */
    public const FILE_REF_META_KEYS = ['alternative', 'title', 'description', 'link', 'crop'];

    public function __construct(
        private TcaSchemaService $tcaSchemaService,
    ) {}

    /**
     * Pull TCA file fields shaped as [{"uid_local": N, ...}] (or []) out of the payload.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     0: array<string, mixed>,
     *     1: array<string, list<array{uid_local: int, alternative?: string, title?: string, description?: string, link?: string, crop?: string}>>
     * }
     */
    public function extractFileFields(string $tableName, array $payload): array
    {
        $extracted = [];
        foreach ($this->tcaSchemaService->getFileFields($tableName) as $fieldName) {
            if (!array_key_exists($fieldName, $payload)) {
                continue;
            }

            $value = $payload[$fieldName];
            if (!is_array($value) || !$this->isUidLocalReferenceList($value)) {
                continue;
            }

            $normalized = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $uidLocal = (int) ($item['uid_local'] ?? 0);
                if ($uidLocal <= 0) {
                    continue;
                }
                $ref = ['uid_local' => $uidLocal];
                foreach (self::FILE_REF_META_KEYS as $metaKey) {
                    if (isset($item[$metaKey]) && is_string($item[$metaKey])) {
                        $ref[$metaKey] = $item[$metaKey];
                    }
                }
                $normalized[] = $ref;
            }

            $extracted[$fieldName] = $normalized;
            unset($payload[$fieldName]);
        }

        return [$payload, $extracted];
    }

    /**
     * Relation fields (category / MM select / MM group) are written as a comma-separated uid string.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizeRelationUidListFields(string $tableName, array $payload): array
    {
        foreach ($this->tcaSchemaService->getRelationUidListFields($tableName) as $fieldName) {
            if (!array_key_exists($fieldName, $payload)) {
                continue;
            }
            $value = $payload[$fieldName];
            if (is_int($value) || is_float($value)) {
                $payload[$fieldName] = (string) (int) $value;
                continue;
            }
            if (is_array($value)) {
                $uids = [];
                foreach ($value as $item) {
                    if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                        $uids[] = (string) (int) $item;
                    }
                }
                $payload[$fieldName] = implode(',', $uids);
            }
        }

        return $payload;
    }

    /**
     * Keeps only the fields DataHandler may be given for this table.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function filterWritableFields(string $tableName, array $payload): array
    {
        $writableFields = $this->tcaSchemaService->getWritableFields($tableName);

        return array_intersect_key($payload, array_flip($writableFields));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $filteredData
     * @return list<string>
     */
    public function ignoredFields(array $payload, array $filteredData): array
    {
        return array_values(array_diff(array_keys($payload), array_keys($filteredData)));
    }

    /**
     * @param list<string> $ignoredFields
     * @return list<array<string, mixed>>
     */
    public function ignoredFieldDetails(string $tableName, array $ignoredFields): array
    {
        $details = [];
        foreach ($ignoredFields as $fieldName) {
            $details[] = $this->tcaSchemaService->describeIgnoredField($tableName, $fieldName);
        }

        return $details;
    }

    /**
     * @param list<string> $ignoredFields
     * @return array{ignoredFields: list<string>, ignoredFieldDetails: list<array<string, mixed>>}
     */
    public function ignoredFieldsContext(string $tableName, array $ignoredFields): array
    {
        return [
            'ignoredFields' => $ignoredFields,
            'ignoredFieldDetails' => $this->ignoredFieldDetails($tableName, $ignoredFields),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $filteredData
     */
    public function noWritableFieldsMessage(string $tableName, array $payload, array $filteredData): string
    {
        $ignored = $this->ignoredFields($payload, $filteredData);
        if ($ignored === []) {
            return 'No valid writable fields provided.';
        }

        $hints = [];
        foreach ($this->ignoredFieldDetails($tableName, $ignored) as $detail) {
            $field = (string) ($detail['field'] ?? '');
            $hint = trim((string) ($detail['hint'] ?? ''));
            if ($field === '' || $hint === '') {
                continue;
            }
            $hints[] = $field . ': ' . $hint;
        }

        return $hints !== []
            ? 'No valid writable fields provided. ' . implode(' ', $hints)
            : 'No valid writable fields provided. Ignored: ' . implode(', ', $ignored) . '.';
    }

    /** @param array<mixed> $value */
    private function isUidLocalReferenceList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        foreach ($value as $item) {
            if (!is_array($item) || !array_key_exists('uid_local', $item)) {
                return false;
            }
        }

        return true;
    }
}
