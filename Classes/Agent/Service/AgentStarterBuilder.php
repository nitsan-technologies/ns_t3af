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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Context-aware suggested actions for the agent greeting (prototype parity).
 *
 * @internal
 */
final readonly class AgentStarterBuilder
{
    private const SEO_FLOW_ACTION = 'generate_seo_metadata';

    private const FILE_METADATA_FLOW_ACTION = 'generate_file_metadata';

    public function __construct(
        private PermittedActionProvider $permittedActionProvider,
        private AgentToolPlanResolver $toolPlanResolver,
        private AgentToolEditorLabelService $editorLabelService,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @return array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    public function build(array $context): array
    {
        $catalog = $this->permittedActionProvider->buildCatalog();
        $executable = match ($this->resolveModuleFamily((string) ($context['module'] ?? ''))) {
            'web_layout' => $this->buildWebLayoutStarters($catalog, $context),
            'web_list' => $this->buildWebListStarters($catalog, $context),
            'file' => $this->buildFileStarters($catalog, $context),
            'redirects' => $this->buildRedirectStarters($catalog, $context),
            'scheduler' => $this->buildSchedulerStarters($catalog, $context),
            default => $this->buildFallbackStarters($catalog, $context),
        };

        $executable = array_slice($executable, 0, 4);
        $locked = $this->filterThirdPartyLockedStarters($catalog['locked']);

        if ($executable === [] && $catalog['executable'] !== []) {
            foreach (array_slice($catalog['executable'], 0, 4) as $tool) {
                $executable[] = $this->enrichStarter($tool, []);
            }
        }

        return [
            'executable' => $executable,
            'locked' => $locked,
        ];
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildWebLayoutStarters(array $catalog, array $context): array
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $executable = [];

        if ($pageId > 0 && $this->canRunSeoFlow($catalog)) {
            $executable[] = $this->actionStarter(
                self::SEO_FLOW_ACTION,
                'agent.starter.generateSeo',
                ToolSeverity::Write,
            );
        }

        foreach (['pages_get', 'content_list', 'pages_tree', 'pages_list'] as $toolName) {
            $tool = $this->findExecutableTool($catalog, $toolName);
            if ($tool === null) {
                continue;
            }
            $arguments = match ($toolName) {
                'pages_get' => ['uid' => $pageId],
                'pages_list', 'content_list' => ['pid' => $pageId],
                default => [],
            };
            $executable[] = $this->enrichStarter($tool, $pageId > 0 ? $arguments : []);
        }

        return $executable;
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildWebListStarters(array $catalog, array $context): array
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $executable = [];

        $recordSearch = $this->findExecutableTool($catalog, 'record_search');
        if ($recordSearch !== null) {
            $arguments = $pageId > 0 ? ['pid' => $pageId] : [];
            $executable[] = $this->enrichStarter($recordSearch, $arguments);
        }

        foreach (['pages_get', 'content_list', 'pages_list'] as $toolName) {
            $tool = $this->findExecutableTool($catalog, $toolName);
            if ($tool === null) {
                continue;
            }
            $arguments = $toolName === 'pages_get'
                ? ['uid' => $pageId]
                : ['pid' => $pageId];
            $executable[] = $this->enrichStarter($tool, $pageId > 0 ? $arguments : []);
        }

        return $executable;
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildFileStarters(array $catalog, array $context): array
    {
        unset($context);
        $executable = [];

        if ($this->findExecutableTool($catalog, 't3aa_update_file_metadata') !== null) {
            $executable[] = $this->actionStarter(
                self::FILE_METADATA_FLOW_ACTION,
                'agent.starter.generateFileMetadata',
                ToolSeverity::Write,
            );
        }

        foreach (['file_list', 'file_search', 'file_get_info', 'file_upload'] as $toolName) {
            $tool = $this->findExecutableTool($catalog, $toolName);
            if ($tool === null) {
                continue;
            }
            $executable[] = $this->enrichStarter($tool, []);
        }

        return $executable;
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildRedirectStarters(array $catalog, array $context): array
    {
        $executable = [];

        foreach (['redirect_list', 'redirect_get'] as $toolName) {
            $tool = $this->findExecutableTool($catalog, $toolName);
            if ($tool === null) {
                continue;
            }
            $executable[] = $this->enrichStarter($tool, []);
        }

        if ($executable !== []) {
            return $executable;
        }

        return $this->buildFallbackStarters($catalog, $context);
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildSchedulerStarters(array $catalog, array $context): array
    {
        unset($context);
        $executable = [];

        foreach (['scheduler_list', 'scheduler_get'] as $toolName) {
            $tool = $this->findExecutableTool($catalog, $toolName);
            if ($tool === null) {
                continue;
            }
            $executable[] = $this->enrichStarter($tool, []);
        }

        return $executable;
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function buildFallbackStarters(array $catalog, array $context): array
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $executable = [];

        if ($pageId > 0) {
            if ($this->canRunSeoFlow($catalog)) {
                $executable[] = $this->actionStarter(
                    self::SEO_FLOW_ACTION,
                    'agent.starter.generateSeo',
                    ToolSeverity::Write,
                );
            }

            foreach (['pages_get', 'content_list', 'pages_list'] as $toolName) {
                $tool = $this->findExecutableTool($catalog, $toolName);
                if ($tool === null) {
                    continue;
                }
                $arguments = $toolName === 'pages_get'
                    ? ['uid' => $pageId]
                    : ['pid' => $pageId];
                $executable[] = $this->enrichStarter($tool, $arguments);
            }
        }

        if ($executable === []) {
            $readTools = array_values(array_filter(
                $catalog['executable'],
                static fn(array $tool): bool => ($tool['severity'] ?? '') === ToolSeverity::Read->value,
            ));
            if ($pageId > 0) {
                usort($readTools, static function (array $a, array $b): int {
                    $score = static fn(array $tool): int => str_contains(strtolower((string) $tool['name']), 'page') ? 0 : 1;

                    return $score($a) <=> $score($b);
                });
            }
            foreach (array_slice($readTools, 0, 4) as $tool) {
                $arguments = [];
                if ($pageId > 0 && (string) ($tool['name'] ?? '') === 'pages_get') {
                    $arguments = ['uid' => $pageId];
                }
                $executable[] = $this->enrichStarter($tool, $arguments);
            }
        }

        return $executable;
    }

    private function resolveModuleFamily(string $module): string
    {
        $normalized = strtolower(trim($module));
        if ($normalized === '') {
            return 'fallback';
        }

        if ($normalized === 'web_layout' || str_contains($normalized, 'layout')) {
            return 'web_layout';
        }

        if ($normalized === 'web_list' || $normalized === 'records' || str_contains($normalized, 'records')) {
            return 'web_list';
        }

        if (str_starts_with($normalized, 'file') || $normalized === 'media_management') {
            return 'file';
        }

        if (str_contains($normalized, 'redirect')) {
            return 'redirects';
        }

        if (str_contains($normalized, 'scheduler')) {
            return 'scheduler';
        }

        return 'fallback';
    }

    /**
     * Upsell starters only for tools owned by other extensions — not ns_t3af tools
     * that are locked because draft planning is not implemented yet.
     *
     * @param list<array<string, mixed>> $locked
     * @return list<array<string, mixed>>
     */
    private function filterThirdPartyLockedStarters(array $locked): array
    {
        $filtered = array_values(array_filter(
            $locked,
            static fn(array $tool): bool => ($tool['ownerExtensionKey'] ?? 'ns_t3af') !== 'ns_t3af',
        ));

        return array_slice($filtered, 0, 4);
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     */
    private function canRunSeoFlow(array $catalog): bool
    {
        return $this->findExecutableTool($catalog, 'pages_get') !== null
            && $this->findExecutableTool($catalog, 'write_table') !== null
            && $this->toolPlanResolver->supportsPlanning('write_table');
    }

    /**
     * @param array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>} $catalog
     * @return array<string, mixed>|null
     */
    private function findExecutableTool(array $catalog, string $toolName): ?array
    {
        $needle = strtolower($toolName);
        foreach ($catalog['executable'] as $tool) {
            if (strtolower((string) ($tool['name'] ?? '')) === $needle) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function enrichStarter(array $tool, array $arguments): array
    {
        $tool['label'] = (string) ($tool['editorLabel'] ?? $this->editorLabelService->resolve($tool));
        if ($arguments !== []) {
            $tool['arguments'] = $arguments;
        }

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private function actionStarter(string $action, string $labelKey, ToolSeverity $severity): array
    {
        return [
            'name' => $action,
            'action' => $action,
            'label' => $this->translate($labelKey),
            'description' => $this->translate($labelKey),
            'severity' => $severity->value,
            'severityLabel' => $severity->label(),
            'ownerExtensionKey' => 'ns_t3af',
            'ownerLabel' => $this->translate('agent.owner.core'),
            'executable' => true,
            'lockReason' => '',
        ];
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key;
        $value = $languageService instanceof LanguageService
            ? (string) $languageService->sL($label)
            : $key;

        return $value !== '' ? $value : $key;
    }
}
