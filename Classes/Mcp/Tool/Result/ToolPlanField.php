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
 * One proposed field change within a {@see ToolPlan}.
 */
final readonly class ToolPlanField
{
    public function __construct(
        public string $key,
        public string $table,
        public int $uid,
        public string $field,
        public mixed $currentValue,
        public mixed $proposedValue,
    ) {}

    /**
     * @return array{key: string, table: string, uid: int, field: string, currentValue: mixed, proposedValue: mixed}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'table' => $this->table,
            'uid' => $this->uid,
            'field' => $this->field,
            'currentValue' => $this->currentValue,
            'proposedValue' => $this->proposedValue,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['key'] ?? ''),
            (string) ($data['table'] ?? ''),
            (int) ($data['uid'] ?? 0),
            (string) ($data['field'] ?? ''),
            $data['currentValue'] ?? null,
            $data['proposedValue'] ?? null,
        );
    }

    public static function buildKey(string $table, int $uid, string $field): string
    {
        return $table . ':' . $uid . ':' . $field;
    }
}
