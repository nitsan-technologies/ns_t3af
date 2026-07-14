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

namespace NITSAN\NsT3AF\Settings;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * File-based extension settings defaults without DI or database access.
 *
 * Used during early bootstrap (ext_localconf, TCA overrides, JavaScriptModules.php)
 * where resolving ExtensionSettingsService would recurse through the container.
 *
 * @internal
 */
class ExtensionSettingsBootstrapReader
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $cache = [];

    /**
     * @return array<string, string>
     */
    public static function getDefaults(string $extensionKey): array
    {
        if (isset(self::$cache[$extensionKey])) {
            return self::$cache[$extensionKey];
        }

        try {
            $basePath = ExtensionManagementUtility::extPath($extensionKey) . 'Configuration/ExtensionSettings/';
        } catch (\Throwable) {
            return self::$cache[$extensionKey] = [];
        }

        $schemaPath = $basePath . 'schema.php';
        if (!is_file($schemaPath)) {
            return self::$cache[$extensionKey] = [];
        }

        /** @var array<string, mixed> $schema */
        $schema = require $schemaPath;

        $templatePath = self::resolveFieldsTemplatePath($schema, $basePath);
        if ($templatePath === null) {
            return self::$cache[$extensionKey] = [];
        }

        return self::$cache[$extensionKey] = self::parseFieldsTemplate($templatePath);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function resolveFieldsTemplatePath(array $schema, string $basePath): ?string
    {
        $template = $schema['fieldsTemplate'] ?? null;
        if (!is_string($template) || $template === '') {
            $fallback = $basePath . 'fields.typoscript';
            return is_file($fallback) ? $fallback : null;
        }

        if (is_file($template)) {
            return $template;
        }

        $relative = $basePath . basename($template);

        return is_file($relative) ? $relative : null;
    }

    /**
     * @return array<string, string>
     */
    private static function parseFieldsTemplate(string $templatePath): array
    {
        $content = (string) file_get_contents($templatePath);
        $defaults = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([a-zA-Z0-9_]+)\s*=\s*(.*)$/', $line, $matches)) {
                continue;
            }
            $defaults[$matches[1]] = trim($matches[2]);
        }

        return $defaults;
    }
}
