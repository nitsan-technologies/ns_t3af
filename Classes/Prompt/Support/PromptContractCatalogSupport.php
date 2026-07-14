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

namespace NITSAN\NsT3AF\Prompt\Support;

/**
 * Shared catalog helpers for prompt contract registries.
 */
final class PromptContractCatalogSupport
{
    /**
     * @param array<string, string> $scopeLabels
     * @return array{
     *   available: bool,
     *   scopes: list<array{id: string, label: string}>,
     *   typesByScope: array<string, list<array{id: string, label: string}>>,
     *   variablesByType: array<string, list<string>>,
     *   defaultTextByType: array<string, string>
     * }
     */
    public static function buildUiCatalogFromRegistry(object $registry, array $scopeLabels = []): array
    {
        if (!is_callable([$registry, 'getPromptTypes'])) {
            return self::emptyCatalog();
        }

        $scopesSeen = [];
        $typesByScope = [];
        $variablesByType = [];
        $defaultTextByType = [];

        foreach ($registry->getPromptTypes() as $promptType) {
            $promptType = (string) $promptType;
            $scope = is_callable([$registry, 'getScope']) ? (string) $registry->getScope($promptType) : '';
            if ($scope === '') {
                continue;
            }
            $scopesSeen[$scope] = true;
            $typesByScope[$scope] ??= [];
            $typesByScope[$scope][] = [
                'id' => $promptType,
                'label' => self::humanizePromptType($promptType),
            ];
            $variablesByType[$promptType] = is_callable([$registry, 'getRequiredVariables'])
                ? $registry->getRequiredVariables($promptType)
                : [];
            $defaultTextByType[$promptType] = is_callable([$registry, 'getDefaultText'])
                ? (string) $registry->getDefaultText($promptType)
                : '';
        }

        foreach ($typesByScope as $scope => $types) {
            usort(
                $typesByScope[$scope],
                static fn(array $a, array $b): int => strcmp($a['id'], $b['id']),
            );
        }

        $scopes = [];
        foreach (array_keys($scopesSeen) as $scope) {
            $scopes[] = [
                'id' => $scope,
                'label' => $scopeLabels[$scope] ?? ucfirst($scope),
            ];
        }
        usort($scopes, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return [
            'available' => true,
            'scopes' => $scopes,
            'typesByScope' => $typesByScope,
            'variablesByType' => $variablesByType,
            'defaultTextByType' => $defaultTextByType,
        ];
    }

    public static function validateGlobalPrompt(object $registry, string $promptType, string $scope, string $promptText): ?string
    {
        if (!is_callable([$registry, 'has']) || !$registry->has($promptType)) {
            return 'invalid_prompt_type';
        }

        if (is_callable([$registry, 'getScope']) && $registry->getScope($promptType) !== $scope) {
            return 'scope_mismatch';
        }

        if (is_callable([$registry, 'textContainsRequiredVariables'])
            && !$registry->textContainsRequiredVariables($promptType, $promptText)) {
            return 'missing_required_variables';
        }

        return null;
    }

    /**
     * @return list<array{uid:int,prompt_type:string,scope:string,prompt_title:string,prompt_text:string,isBuiltin:bool}>
     */
    public static function getBuiltinPromptRowsForScope(object $registry, string $scope): array
    {
        if ($scope === '') {
            return [];
        }

        if (is_callable([$registry, 'getCatalogRowsForScope'])) {
            $result = $registry->getCatalogRowsForScope($scope);

            return is_array($result)
                ? array_map(static fn(array $row): array => [
                    'uid' => (int) $row['uid'],
                    'prompt_type' => (string) $row['prompt_type'],
                    'scope' => (string) $row['scope'],
                    'prompt_title' => (string) $row['prompt_title'],
                    'prompt_text' => (string) $row['prompt_text'],
                    'isBuiltin' => true,
                    'engineProfile' => (string) ($row['engineProfile'] ?? ''),
                    'resultStyle' => (string) ($row['resultStyle'] ?? ''),
                    'dataSource' => (string) ($row['dataSource'] ?? ''),
                ], $result)
                : [];
        }

        if (!is_callable([$registry, 'getPromptTypesForScope'])) {
            return [];
        }

        $rows = [];
        $uid = -1;
        foreach ($registry->getPromptTypesForScope($scope) as $promptType) {
            $promptType = (string) $promptType;
            $label = is_callable([$registry, 'getLabel']) ? (string) $registry->getLabel($promptType) : '';
            $rows[] = [
                'uid' => $uid,
                'prompt_type' => $promptType,
                'scope' => $scope,
                'prompt_title' => self::resolvePromptTitle($label, $promptType),
                'prompt_text' => is_callable([$registry, 'getDefaultText']) ? (string) $registry->getDefaultText($promptType) : '',
                'isBuiltin' => true,
            ];
            $uid--;
        }

        return $rows;
    }

    /**
     * @return array{
     *   available: bool,
     *   scopes: list<array{id: string, label: string}>,
     *   typesByScope: array<string, list<array{id: string, label: string}>>,
     *   variablesByType: array<string, list<string>>,
     *   defaultTextByType: array<string, string>
     * }
     */
    public static function emptyCatalog(): array
    {
        return [
            'available' => false,
            'scopes' => [],
            'typesByScope' => [],
            'variablesByType' => [],
            'defaultTextByType' => [],
        ];
    }

    private static function resolvePromptTitle(string $label, string $promptType): string
    {
        if (str_starts_with($label, 'LLL:')) {
            $languageService = $GLOBALS['LANG'] ?? null;
            if ($languageService instanceof \TYPO3\CMS\Core\Localization\LanguageService) {
                $translated = trim((string) $languageService->sL($label));
                if ($translated !== '' && $translated !== $label && !str_starts_with($translated, 'LLL:')) {
                    return $translated;
                }
            }
        }

        if ($label !== '') {
            return $label;
        }

        return self::humanizePromptType($promptType);
    }

    private static function humanizePromptType(string $promptType): string
    {
        return ucwords(str_replace('_', ' ', $promptType));
    }
}
