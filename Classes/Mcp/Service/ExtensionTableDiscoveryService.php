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

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locale;

/**
 * Scans TCA for extension tables eligible for MCP dynamic tools.
 */
readonly class ExtensionTableDiscoveryService
{
    private const EXCLUDED_PREFIXES = ['sys_', 'be_', 'fe_', 'cache_', 'cf_', 'index_', 'tx_nst3af_'];

    private const EXCLUDED_TABLES = ['pages', 'tt_content'];

    public function __construct(private LanguageServiceFactory $languageServiceFactory) {}

    /**
     * @return array<string, array{label: string, prefix: string}>
     */
    public function discoverTables(): array
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return [];
        }

        $extconfTables = $this->getExtconfTables();
        $discovered = [];

        foreach (array_keys($tca) as $tableName) {
            if (!is_string($tableName)) {
                continue;
            }

            if (!$this->isExtensionTable($tableName)) {
                continue;
            }

            if (array_key_exists($tableName, $extconfTables)) {
                continue;
            }

            $discovered[$tableName] = [
                'label' => $this->generateLabel($tableName),
                'prefix' => $this->generatePrefix($tableName),
            ];
        }

        return $discovered;
    }

    public function generateLabel(string $tableName): string
    {
        $tcaAll = $GLOBALS['TCA'] ?? [];
        if (!is_array($tcaAll)) {
            return $this->humanizeTableName($tableName);
        }

        $tca = $tcaAll[$tableName] ?? null;
        if (!is_array($tca)) {
            return $this->humanizeTableName($tableName);
        }

        $ctrl = $tca['ctrl'] ?? [];
        $title = is_array($ctrl) ? ($ctrl['title'] ?? null) : null;
        if (!is_string($title) || $title === '') {
            return $this->humanizeTableName($tableName);
        }

        if (!str_starts_with($title, 'LLL:')) {
            return $title;
        }

        $resolved = $this->resolveLanguageLabel($title);
        if ($resolved !== '' && $resolved !== $title) {
            return $resolved;
        }

        return $this->humanizeTableName($tableName);
    }

    public function generatePrefix(string $tableName): string
    {
        $name = $tableName;

        if (str_starts_with($name, 'tx_')) {
            $name = substr($name, 3);
        }

        if (str_contains($name, '_domain_model_')) {
            $parts = explode('_domain_model_', $name, 2);
            $extKey = $parts[0];
            $modelName = $parts[1];

            if ($extKey === $modelName) {
                return $modelName;
            }

            return $extKey . '_' . $modelName;
        }

        return $name;
    }

    private function isExtensionTable(string $tableName): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($tableName, $prefix)) {
                return false;
            }
        }

        if (in_array($tableName, self::EXCLUDED_TABLES, true)) {
            return false;
        }

        return str_starts_with($tableName, 'tx_');
    }

    /** @return array<mixed> */
    private function getExtconfTables(): array
    {
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3ConfVars)) {
            return [];
        }

        $extConf = $typo3ConfVars['EXTCONF'] ?? [];
        if (!is_array($extConf)) {
            return [];
        }

        $nst3afExtConf = $extConf['ns_t3af'] ?? [];
        if (!is_array($nst3afExtConf)) {
            return [];
        }

        $tables = $nst3afExtConf['tables'] ?? [];

        return is_array($tables) ? $tables : [];
    }

    private function resolveLanguageLabel(string $label): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            return $languageService->sL($label);
        }

        return $this->languageServiceFactory->create(new Locale('en'))->sL($label);
    }

    private function humanizeTableName(string $tableName): string
    {
        $name = $tableName;

        if (str_starts_with($name, 'tx_')) {
            $name = substr($name, 3);
        }

        if (str_contains($name, '_domain_model_')) {
            $parts = explode('_domain_model_', $name, 2);

            return ucwords(str_replace('_', ' ', $parts[0])) . ' ' . ucwords(str_replace('_', ' ', $parts[1]));
        }

        return ucwords(str_replace('_', ' ', $name));
    }
}
