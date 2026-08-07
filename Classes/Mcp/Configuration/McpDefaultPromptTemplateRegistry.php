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

namespace NITSAN\NsT3AF\Mcp\Configuration;

/**
 * Single source of truth for built-in MCP prompt templates.
 */
final class McpDefaultPromptTemplateRegistry
{
    /**
     * Former built-ins removed from the catalog — soft-deleted from DB on ensureDefaults().
     *
     * @var list<string>
     */
    private const RETIRED_BUILTIN_NAMES = [
        'audit_seo',
        'translate_missing',
        'generate_alt_texts',
    ];

    private function getConfigPath(): string
    {
        return dirname(__DIR__, 3) . '/Configuration/McpDefaultPromptTemplates.php';
    }

    /**
     * @return list<array{
     *     name: string,
     *     description: string,
     *     templateBody: string,
     *     arguments: list<array{name: string, required: bool, description: string, default?: string}>
     * }>
     */
    public function getDefaults(): array
    {
        $path = $this->getConfigPath();
        if (!is_file($path)) {
            return [];
        }

        /** @var list<array{name: string, description: string, templateBody: string, arguments: list<array{name: string, required: bool, description: string, default?: string}>}> $defaults */
        $defaults = require $path;

        return array_values(array_filter(
            $defaults,
            static fn(array $row): bool => ($row['name'] ?? '') !== '' && ($row['templateBody'] ?? '') !== '',
        ));
    }

    public function isBuiltinName(string $name): bool
    {
        return in_array($name, $this->getBuiltinNames(), true);
    }

    /**
     * @return list<string>
     */
    public function getBuiltinNames(): array
    {
        return array_map(
            static fn(array $row): string => (string) $row['name'],
            $this->getDefaults(),
        );
    }

    /**
     * @return list<string>
     */
    public function getRetiredBuiltinNames(): array
    {
        return self::RETIRED_BUILTIN_NAMES;
    }
}
