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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads static MCP tool metadata from Configuration/McpToolMetadata.yaml.
 */
final class McpToolMetadataService
{
    private const DEFAULT_CATEGORY = 'Records';

    private const DEFAULT_STATUS = 'ready';

    /** @var array<string, mixed>|null */
    private ?array $configCache = null;

    public function __construct(
        private readonly ?string $configFilePath = null,
    ) {}

    /**
     * @return array{
     *     category: string,
     *     status: string,
     *     tagline: string,
     *     notes: string,
     *     examplePrompts: list<string>
     * }
     */
    public function getForTool(string $name): array
    {
        $config = $this->loadConfig();
        $defaults = $this->normalizeDefaults($config['defaults'] ?? []);
        $toolConfig = $config['tools'][$name] ?? [];

        if (!is_array($toolConfig)) {
            $toolConfig = [];
        }

        return $this->mergeToolDefaults($defaults, $toolConfig);
    }

    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    public function getCategories(): array
    {
        $config = $this->loadConfig();
        $categories = $config['categories'] ?? [];

        if (!is_array($categories)) {
            return [];
        }

        $result = [];
        foreach ($categories as $key => $definition) {
            if (!is_string($key) || !is_array($definition)) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'description' => (string) ($definition['description'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }

        $path = $this->resolveConfigPath();
        if (!is_file($path)) {
            return $this->configCache = [
                'categories' => [],
                'defaults' => [],
                'tools' => [],
            ];
        }

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($path);

        return $this->configCache = $parsed;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array{
     *     category: string,
     *     status: string,
     *     tagline: string,
     *     notes: string,
     *     examplePrompts: list<string>
     * }
     */
    private function normalizeDefaults(array $defaults): array
    {
        return [
            'category' => (string) ($defaults['category'] ?? self::DEFAULT_CATEGORY),
            'status' => (string) ($defaults['status'] ?? self::DEFAULT_STATUS),
            'tagline' => (string) ($defaults['tagline'] ?? ''),
            'notes' => (string) ($defaults['notes'] ?? ''),
            'examplePrompts' => $this->normalizeExamplePrompts($defaults['examplePrompts'] ?? []),
        ];
    }

    /**
     * @param array{
     *     category: string,
     *     status: string,
     *     tagline: string,
     *     notes: string,
     *     examplePrompts: list<string>
     * } $defaults
     * @param array<string, mixed> $toolConfig
     * @return array{
     *     category: string,
     *     status: string,
     *     tagline: string,
     *     notes: string,
     *     examplePrompts: list<string>
     * }
     */
    private function mergeToolDefaults(array $defaults, array $toolConfig): array
    {
        $merged = $defaults;

        if (isset($toolConfig['category']) && is_string($toolConfig['category']) && $toolConfig['category'] !== '') {
            $merged['category'] = $toolConfig['category'];
        }

        if (isset($toolConfig['status']) && is_string($toolConfig['status']) && $toolConfig['status'] !== '') {
            $merged['status'] = $toolConfig['status'];
        }

        if (array_key_exists('tagline', $toolConfig)) {
            $merged['tagline'] = (string) $toolConfig['tagline'];
        }

        if (array_key_exists('notes', $toolConfig)) {
            $merged['notes'] = (string) $toolConfig['notes'];
        }

        if (isset($toolConfig['examplePrompts'])) {
            $merged['examplePrompts'] = $this->normalizeExamplePrompts($toolConfig['examplePrompts']);
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function normalizeExamplePrompts(mixed $prompts): array
    {
        if (!is_array($prompts)) {
            return [];
        }

        $normalized = [];
        foreach ($prompts as $prompt) {
            if (!is_string($prompt)) {
                continue;
            }

            $trimmed = trim($prompt);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    private function resolveConfigPath(): string
    {
        if ($this->configFilePath !== null && $this->configFilePath !== '') {
            return $this->configFilePath;
        }

        return dirname(__DIR__, 4) . '/Configuration/McpToolMetadata.yaml';
    }
}
