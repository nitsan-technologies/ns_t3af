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

namespace NITSAN\NsT3AF\Mcp\Tool\Result;

/**
 * Dry-run description of a write tool invocation (Phase 0b).
 */
final readonly class ToolPlan
{
    /**
     * @param list<ToolPlanField> $fields
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $action,
        public string $toolName,
        public array $fields,
        public array $context = [],
    ) {}

    /**
     * @return array{action: string, toolName: string, fields: list<array<string, mixed>>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'toolName' => $this->toolName,
            'fields' => array_map(static fn(ToolPlanField $field): array => $field->toArray(), $this->fields),
            'context' => $this->context,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawFields = $data['fields'] ?? [];
        $fields = [];
        if (is_array($rawFields)) {
            foreach ($rawFields as $rawField) {
                if (is_array($rawField)) {
                    $fields[] = ToolPlanField::fromArray($rawField);
                }
            }
        }

        $context = $data['context'] ?? [];

        return new self(
            (string) ($data['action'] ?? ''),
            (string) ($data['toolName'] ?? ''),
            $fields,
            is_array($context) ? $context : [],
        );
    }

    /**
     * @param list<string> $keptFieldKeys
     * @return list<ToolPlanField>
     */
    public function keptFields(array $keptFieldKeys): array
    {
        if ($keptFieldKeys === []) {
            return [];
        }

        $lookup = array_flip($keptFieldKeys);

        return array_values(array_filter(
            $this->fields,
            static fn(ToolPlanField $field): bool => isset($lookup[$field->key]),
        ));
    }
}
