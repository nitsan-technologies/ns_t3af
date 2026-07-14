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

use TYPO3\CMS\Core\TypoScript\AST\CommentAwareAstBuilder;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\AST\Traverser\AstTraverser;
use TYPO3\CMS\Core\TypoScript\AST\Visitor\AstConstantCommentVisitor;
use TYPO3\CMS\Core\TypoScript\Tokenizer\LosslessTokenizer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Loads per-extension schema and builds drawer field metadata from fields.typoscript.
 *
 * @internal
 */
class ExtensionSettingsSchemaService
{
    public function __construct(
        private readonly ExtensionSettingsRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function loadSchemaConfig(string $extensionKey): array
    {
        $path = $this->registry->getSchemaPath($extensionKey);
        if ($path === null) {
            return [];
        }

        /** @var array<string, mixed> $schema */
        $schema = require $path;

        return $schema;
    }

    /**
     * @return array<string, string>
     */
    public function getDefaults(string $extensionKey): array
    {
        if (!$this->hasLanguageService()) {
            return ExtensionSettingsBootstrapReader::getDefaults($extensionKey);
        }

        $schema = $this->loadSchemaConfig($extensionKey);
        $defaults = [];

        $templatePath = $this->resolveFieldsTemplatePath($schema);
        if ($templatePath === null) {
            return $defaults;
        }

        foreach ($this->parseFieldsTemplate($templatePath, []) as $constant) {
            $name = (string) ($constant['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $defaults[$name] = (string) ($constant['value'] ?? '');
        }

        return $defaults;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    public function getDisplayConstants(string $extensionKey, array $values): array
    {
        $schema = $this->loadSchemaConfig($extensionKey);
        $templatePath = $this->resolveFieldsTemplatePath($schema);
        if ($templatePath === null) {
            return [];
        }

        return $this->groupConstantsForDisplay(
            $this->parseFieldsTemplate($templatePath, $values),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getConstantsByFieldName(string $extensionKey, array $values): array
    {
        $byName = [];
        $display = $this->getDisplayConstants($extensionKey, $values);
        foreach ($display as $category) {
            if (!is_array($category)) {
                continue;
            }
            foreach ($category as $subcategory) {
                if (!is_array($subcategory)) {
                    continue;
                }
                foreach ($subcategory['items'] ?? [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? '');
                    if ($name !== '') {
                        $byName[$name] = $item;
                    }
                }
            }
        }

        return $byName;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function resolveFieldsTemplatePath(array $schema): ?string
    {
        $template = $schema['fieldsTemplate'] ?? null;
        if (!is_string($template) || $template === '') {
            return null;
        }

        return is_file($template) ? $template : null;
    }

    /**
     * @param array<string, string> $values
     * @return list<array<string, mixed>>
     */
    private function parseFieldsTemplate(string $templatePath, array $values): array
    {
        $astBuilder = GeneralUtility::makeInstance(CommentAwareAstBuilder::class);
        $losslessTokenizer = GeneralUtility::makeInstance(LosslessTokenizer::class);
        $astTraverser = GeneralUtility::makeInstance(AstTraverser::class);

        $ast = $astBuilder->build(
            $losslessTokenizer->tokenize((string) file_get_contents($templatePath)),
            new RootNode(),
        );
        $astConstantCommentVisitor = GeneralUtility::makeInstance(AstConstantCommentVisitor::class);
        $astTraverser->traverse($ast, [$astConstantCommentVisitor]);
        $constants = $astConstantCommentVisitor->getConstants();

        $parsed = [];
        foreach ($constants as $constant) {
            if (!is_array($constant)) {
                continue;
            }
            $name = (string) ($constant['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $constant['value'] = $values[$name] ?? (string) ($constant['value'] ?? '');
            $parsed[] = $constant;
        }

        return $parsed;
    }

    /**
     * @param list<array<string, mixed>> $constants
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function groupConstantsForDisplay(array $constants): array
    {
        $displayConstants = [];
        foreach ($constants as $constant) {
            $displayConstants[$constant['cat']][$constant['subcat_sorting_first']]['label'] = $constant['subcat_label'];
            $displayConstants[$constant['cat']][$constant['subcat_sorting_first']]['items'][$constant['subcat_sorting_second']] = $constant;
        }
        foreach ($displayConstants as &$constantCategory) {
            ksort($constantCategory);
            foreach ($constantCategory as &$constantDetailItems) {
                ksort($constantDetailItems['items']);
            }
        }
        unset($constantCategory, $constantDetailItems);

        return $displayConstants;
    }

    private function hasLanguageService(): bool
    {
        return ($GLOBALS['LANG'] ?? null) instanceof \TYPO3\CMS\Core\Localization\LanguageService;
    }
}
