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

namespace NITSAN\NsT3AF\Mcp\Dto;

/**
 * Generated suggestions for the AI Agent (preview → card → context apply).
 *
 * Same payload shape as the Phase 5 suggestions meta (`type: 'suggestions'`).
 *
 * @api
 */
final readonly class PreviewResult
{
    public const PATH_ENVELOPE = 'envelope';

    public const PATH_FALLBACK_SINGLES = 'fallback_singles';

    /**
     * @param array{table: string, uid: int, languageId: int}                                                         $target
     * @param list<array{key: string, label: string, current: string, limits?: array<string, mixed>}>                   $fields
     * @param list<array{label: string, angle: string, values: array<string, string>}>                                  $variants
     * @param list<array{table: string, uid: int, languageId: int}>                                                     $targets
     */
    public function __construct(
        public string $tool,
        public array $target,
        public array $fields,
        public array $variants,
        public string $llmSummary = '',
        public int $callCount = 1,
        public string $generationPath = self::PATH_FALLBACK_SINGLES,
        public array $targets = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'target' => $this->target,
            'targets' => $this->targets,
            'fields' => $this->fields,
            'variants' => $this->variants,
            'llmSummary' => $this->llmSummary,
            'callCount' => $this->callCount,
            'generationPath' => $this->generationPath,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $targetRaw = is_array($data['target'] ?? null) ? $data['target'] : [];
        $target = [
            'table' => (string) ($targetRaw['table'] ?? ''),
            'uid' => (int) ($targetRaw['uid'] ?? 0),
            'languageId' => (int) ($targetRaw['languageId'] ?? 0),
        ];

        $fields = [];
        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $entry = [
                'key' => (string) ($field['key'] ?? ''),
                'label' => (string) ($field['label'] ?? ''),
                'current' => (string) ($field['current'] ?? ''),
            ];
            if (isset($field['limits']) && is_array($field['limits'])) {
                $entry['limits'] = $field['limits'];
            }
            $fields[] = $entry;
        }

        $variants = [];
        foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $values = [];
            foreach (is_array($variant['values'] ?? null) ? $variant['values'] : [] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $values[$key] = (string) $value;
                }
            }
            $variants[] = [
                'label' => (string) ($variant['label'] ?? ''),
                'angle' => (string) ($variant['angle'] ?? ''),
                'values' => $values,
            ];
        }

        $targets = [];
        foreach (is_array($data['targets'] ?? null) ? $data['targets'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $targets[] = [
                'table' => (string) ($item['table'] ?? ''),
                'uid' => (int) ($item['uid'] ?? 0),
                'languageId' => (int) ($item['languageId'] ?? 0),
            ];
        }

        return new self(
            tool: (string) ($data['tool'] ?? ''),
            target: $target,
            fields: $fields,
            variants: $variants,
            llmSummary: (string) ($data['llmSummary'] ?? ''),
            callCount: max(1, (int) ($data['callCount'] ?? 1)),
            generationPath: (string) ($data['generationPath'] ?? self::PATH_FALLBACK_SINGLES),
            targets: $targets,
        );
    }

    /**
     * Resolve selected variant values for apply (field key → text).
     *
     * @param array<string, int> $selections fieldKey => variant index
     * @return array<string, string>
     */
    public function resolveSelections(array $selections): array
    {
        $resolved = [];
        foreach ($selections as $fieldKey => $variantIndex) {
            if (!is_string($fieldKey) || $fieldKey === '') {
                continue;
            }
            $index = (int) $variantIndex;
            if (!isset($this->variants[$index])) {
                continue;
            }
            $values = is_array($this->variants[$index]['values'] ?? null)
                ? $this->variants[$index]['values']
                : [];
            if (!isset($values[$fieldKey]) || !is_scalar($values[$fieldKey])) {
                continue;
            }
            $resolved[$fieldKey] = (string) $values[$fieldKey];
        }

        return $resolved;
    }
}
