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

/**
 * The tools offered to the model at the start of every turn.
 *
 * - The stable core: context reads, clarification and capabilities.
 * - The tools of the current backend module (declared with #[McpToolIntent(modules: …)],
 *   or by category), for example SEO and content tools in the page module.
 * - Tools the editor used in the last messages, so "do the same for page 12" works.
 *
 * Everything else is found with find_tools. The list is sorted by name, so the same
 * module gives the same tool list and the provider can cache the prompt prefix.
 *
 * @internal
 */
final readonly class AgentCoreToolSet
{
    /** @var list<string> */
    public const CORE_TOOLS = [
        'ask_clarification',
        'content_get',
        'content_list',
        'content_search',
        'explain_capabilities',
        'pages_get',
        'pages_search',
        'pages_tree',
        'record_search',
        'site_languages_list',
    ];

    public const MAX_MODULE_TOOLS = 20;

    public const MAX_RECENT_TOOLS = 4;

    private const RECENT_MESSAGES = 6;

    /**
     * Categories per backend module; intent categories and McpToolMetadata.yaml categories (lower case).
     *
     * @var array<string, list<string>>
     */
    private const MODULE_CATEGORIES = [
        'web_layout' => ['pages', 'content', 'seo', 'translation'],
        'records' => ['records', 'content', 'seo'],
        'web_list' => ['records', 'content', 'seo'],
        'file' => ['media_files', 'files'],
        'redirects' => ['redirects'],
        'site_redirects' => ['redirects'],
        'scheduler' => ['scheduler'],
        'workspaces_admin' => ['workspaces'],
    ];

    public function __construct(
        private AgentToolDocumentBuilder $documentBuilder,
    ) {}

    /**
     * @param list<array<string, mixed>> $executableTools
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $historyMessages
     * @return list<array<string, mixed>>
     */
    public function forTurn(array $executableTools, array $context, array $historyMessages = []): array
    {
        $byName = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name !== '') {
                $byName[$name] = $tool;
            }
        }

        $picked = [];
        foreach (self::CORE_TOOLS as $name) {
            if (isset($byName[$name])) {
                $picked[$name] = $byName[$name];
            }
        }

        $module = $this->normalizeModule((string) ($context['module'] ?? ''));
        if ($module !== '') {
            // Tools that declare the module come first, then tools of the module's categories.
            $declared = [];
            $byCategory = [];
            foreach ($byName as $name => $tool) {
                if (isset($picked[$name])) {
                    continue;
                }
                if ($this->declaresModule($tool, $module)) {
                    $declared[$name] = $tool;
                } elseif ($this->matchesModuleCategory($tool, $module)) {
                    $byCategory[$name] = $tool;
                }
            }
            $picked += array_slice($declared + $byCategory, 0, self::MAX_MODULE_TOOLS, true);
        }

        foreach ($this->recentToolNames($historyMessages) as $name) {
            if (isset($byName[$name])) {
                $picked[$name] = $byName[$name];
            }
        }

        ksort($picked);

        return array_values($picked);
    }

    public function normalizeModule(string $module): string
    {
        $module = strtolower(trim($module));
        if ($module === 'media_management' || str_starts_with($module, 'file')) {
            return 'file';
        }

        return $module;
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function declaresModule(array $tool, string $module): bool
    {
        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
        foreach (is_array($intent['modules'] ?? null) ? $intent['modules'] : [] as $declared) {
            if ($this->normalizeModule((string) $declared) === $module) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function matchesModuleCategory(array $tool, string $module): bool
    {
        $category = $this->documentBuilder->category($tool);

        return $category !== '' && in_array($category, self::MODULE_CATEGORIES[$module] ?? [], true);
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @return list<string>
     */
    private function recentToolNames(array $historyMessages): array
    {
        $names = [];
        foreach (array_reverse(array_slice($historyMessages, -self::RECENT_MESSAGES)) as $message) {
            $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
            $tool = trim((string) ($meta['tool'] ?? ''));
            if ($tool !== '' && !in_array($tool, $names, true)) {
                $names[] = $tool;
            }
            if (count($names) >= self::MAX_RECENT_TOOLS) {
                break;
            }
        }

        return $names;
    }
}
