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

namespace NITSAN\NsT3AF\Bootstrap;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Loads the bundled t3af.phar shipped in Resources/Private/Libs/.
 *
 * No-op in Composer mode (Symfony AI bridges installed via host composer.json)
 * and when phar ext is missing or the file is absent.
 */
final class T3afPharBootstrap
{
    public const EXT_KEY = 'ns_t3af';

    public const VENDOR_PREFIX = 'NITSAN\T3af\\Vendor\\';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        if (Environment::isComposerMode() || !extension_loaded('phar')) {
            return;
        }

        // __DIR__ avoids GeneralUtility dependency — safe to call before full TYPO3 bootstrap
        $pharFile = dirname(__DIR__, 2) . '/Resources/Private/Libs/t3af.phar';
        if (!is_file($pharFile)) {
            return;
        }

        // The phar is fully self-contained: its scoped Symfony Uid (and every other
        // bundled dep) is internally consistent. Do NOT alias host Symfony classes
        // into the NITSAN\T3af\Vendor\ namespace — doing so swaps a scoped type
        // for an un-scoped host type and breaks scoped type-hints (e.g. UserMessage::$id
        // typed AbstractUid rejecting a host UuidV7).
        $pharAutoloader = require 'phar://' . $pharFile . '/vendor/autoload.php';

        self::registerMcpNamespaceAliases();
        if ($pharAutoloader instanceof \Composer\Autoload\ClassLoader) {
            self::aliasPrefixedMcpClassesFromClassMap($pharAutoloader);
        }

        if (class_exists(\NITSAN\T3af\Runtime\Bootstrap::class)) {
            \NITSAN\T3af\Runtime\Bootstrap::register();
        }

        if (class_exists(\NITSAN\T3af\Runtime\PlatformRegistry::class)) {
            $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXT_KEY]['t3af_phar_version']
                = \NITSAN\T3af\Runtime\PlatformRegistry::VERSION;
        }
    }

    public static function isLoaded(): bool
    {
        return class_exists(\NITSAN\T3af\Runtime\PlatformRegistry::class, false);
    }

    public static function isMcpVendorLoaded(): bool
    {
        return class_exists(self::VENDOR_PREFIX . 'Mcp\\Capability\\Attribute\\McpTool', false);
    }

    private static function registerMcpNamespaceAliases(): void
    {
        if (!class_exists(self::VENDOR_PREFIX . 'Mcp\\Capability\\Attribute\\McpTool')) {
            return;
        }

        spl_autoload_register(
            static function (string $className): void {
                if (!str_starts_with($className, 'Mcp\\')) {
                    return;
                }

                $prefixed = self::VENDOR_PREFIX . $className;

                if (class_exists($className, false) || interface_exists($className, false) || trait_exists($className, false)) {
                    return;
                }

                if (class_exists($prefixed) || interface_exists($prefixed) || trait_exists($prefixed)) {
                    class_alias($prefixed, $className);
                }
            },
            prepend: true,
        );
    }

    /**
     * Eagerly alias scoped MCP classes to their public `Mcp\…` names so reflection,
     * DI autowiring, and SDK code that references `Mcp\…` share one implementation.
     */
    private static function aliasPrefixedMcpClassesFromClassMap(\Composer\Autoload\ClassLoader $loader): void
    {
        foreach ($loader->getClassMap() as $className => $_path) {
            if (!str_starts_with($className, self::VENDOR_PREFIX . 'Mcp\\')) {
                continue;
            }

            $publicName = substr($className, strlen(self::VENDOR_PREFIX));
            if ($publicName === '' || class_exists($publicName, false)) {
                continue;
            }

            class_alias($className, $publicName);
        }
    }
}
