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

namespace NITSAN\NsT3AF\Mcp\Service;

/**
 * Resolves the owning TYPO3 extension key for an introspected MCP tool.
 *
 * Precedence:
 * 1. Explicit ownerExtensionKey on the tool (McpToolOwner attribute)
 * 2. Namespace inference from handler class name
 * 3. Catalog toolPrefix / explicit tools list match
 * 4. Legacy EXTCONF catalog (already merged into $extensionConfigs)
 *
 * Returns null when the tool belongs to the TYPO3 Core card (ns_t3af handlers).
 *
 * @internal
 */
readonly class McpToolOwnershipResolver
{
    private const FOUNDATION_EXTENSION_KEY = 'ns_t3af';

    /**
     * @param array{
     *     name: string,
     *     className: string,
     *     ownerExtensionKey?: string|null
     * } $tool
     * @param array<string, array<string, mixed>> $extensionConfigs
     */
    public function resolve(array $tool, array $extensionConfigs): ?string
    {
        $explicitOwner = $tool['ownerExtensionKey'] ?? null;
        if (is_string($explicitOwner) && $explicitOwner !== '') {
            return $this->normalizeOwnerKey($explicitOwner);
        }

        $namespaceOwner = $this->inferFromClassName($tool['className']);
        if ($namespaceOwner !== null && $namespaceOwner !== self::FOUNDATION_EXTENSION_KEY) {
            return $namespaceOwner;
        }

        $catalogOwner = $this->matchFromExtensionConfigs($tool['name'], $extensionConfigs);
        if ($catalogOwner !== null) {
            return $catalogOwner;
        }

        if ($namespaceOwner === self::FOUNDATION_EXTENSION_KEY) {
            return null;
        }

        return null;
    }

    /**
     * @param string $className Fully-qualified class name (need not exist at analyse time).
     */
    public function inferFromClassName(string $className): ?string
    {
        if (!str_contains($className, '\\')) {
            return null;
        }

        if (preg_match('/^NITSAN\\\\NsT3([A-Za-z0-9]+)\\\\/', $className, $matches) === 1) {
            return 'ns_t3' . strtolower($matches[1]);
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $extensionConfigs
     */
    private function matchFromExtensionConfigs(string $toolName, array $extensionConfigs): ?string
    {
        foreach ($extensionConfigs as $extensionKey => $config) {
            if (!is_array($config)) {
                continue;
            }

            $explicitTools = $config['tools'] ?? [];
            if (is_array($explicitTools) && $explicitTools !== []) {
                if (in_array($toolName, array_map('strval', $explicitTools), true)) {
                    return (string) ($config['extensionKey'] ?? $extensionKey);
                }
                continue;
            }

            $toolPrefix = (string) ($config['toolPrefix'] ?? '');
            if ($toolPrefix !== '' && str_starts_with($toolName, $toolPrefix)) {
                return (string) ($config['extensionKey'] ?? $extensionKey);
            }
        }

        return null;
    }

    private function normalizeOwnerKey(string $extensionKey): ?string
    {
        if ($extensionKey === self::FOUNDATION_EXTENSION_KEY) {
            return null;
        }

        return $extensionKey;
    }
}
