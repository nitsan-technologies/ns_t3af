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

namespace NITSAN\NsT3AF\Access\Dto;

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;

/**
 * Which AI Agent tools the backend groups of an editor allow (AI Permissions → Limits).
 *
 * - readOnly: only tools declared as Read may run.
 * - blockedTools: tool names, with `*` as wildcard (e.g. `t3cs_*`, `file_delete`).
 *
 * @internal
 */
final readonly class AgentToolPolicy
{
    /**
     * @param list<string> $blockedTools
     */
    public function __construct(
        public bool $readOnly = false,
        public array $blockedTools = [],
    ) {}

    public static function unrestricted(): self
    {
        return new self();
    }

    public function blocks(string $toolName, ?ToolSeverity $severity): bool
    {
        if ($this->readOnly && $severity !== ToolSeverity::Read) {
            return true;
        }
        $toolName = strtolower($toolName);
        foreach ($this->blockedTools as $pattern) {
            if (fnmatch(strtolower($pattern), $toolName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes a list or a comma/newline separated string of tool names.
     *
     * @return list<string>
     */
    public static function parseToolList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,;]+/', is_string($value) ? $value : '');
        $names = [];
        foreach ($items ?: [] as $item) {
            $name = strtolower(trim(is_scalar($item) ? (string) $item : ''));
            if ($name !== '' && preg_match('/^[a-z0-9_*]+$/', $name) === 1 && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
