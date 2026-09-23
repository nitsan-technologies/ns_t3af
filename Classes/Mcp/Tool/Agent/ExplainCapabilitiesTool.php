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

namespace NITSAN\NsT3AF\Mcp\Tool\Agent;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolIntent;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;

/**
 * Always-on agent tool: list what the editor can do in the current context.
 *
 * @internal
 */
#[McpToolSeverity(ToolSeverity::Read)]
#[McpToolIntent(
    verbs: ['explain', 'list', 'show'],
    nouns: ['capabilities', 'tools', 'help', 'actions'],
    modules: ['web_layout', 'records', 'file', 'media_management'],
    examples: [
        'What can you do on this page?',
        'Which tools are available here?',
        'Was kannst du auf dieser Seite?',
        'Explain your capabilities',
    ],
    summary: 'Explain which AI Agent tools and actions are available in the current backend context.',
    category: 'general',
)]
final readonly class ExplainCapabilitiesTool implements McpNonAiToolInterface
{
    private const MAX_LISTED = 8;

    /**
     * Curated name order for page/layout context (reads first, then common writes).
     *
     * @var list<string>
     */
    private const PAGE_PREFERRED = [
        'content_list',
        'pages_get',
        'content_get',
        'content_search',
        'pages_search',
        't3aa_summarize_content',
        't3ai_generate_all_seo',
        't3ai_translate_content',
        'content_delete',
        'content_update',
        'pages_update',
        'content_create',
    ];

    /**
     * @var list<string>
     */
    private const HIDDEN_EXACT = [
        'cache_clear',
        'explain_capabilities',
        'ask_clarification',
        'content_move',
        't3ai_mass_seo_queue_add',
        't3ai_mass_seo_queue_list',
        't3ai_generate_seo_batch',
        't3ai_translate_news',
        't3cs_list_datasources',
        't3cs_training_summary',
        't3cs_usage_analytics_summary',
    ];

    /**
     * @var list<string>
     */
    private const HIDDEN_PREFIXES = [
        'backend_user_',
        'backend_group_',
        'directory_',
        'file_reference_',
        'workspace_',
        'permission_check_',
        'table_schema',
        'record_count',
        'scheduler_',
        'redirect_',
    ];

    public function __construct(
        private AgentActionCatalogInterface $actionCatalog,
    ) {}

    #[McpTool(
        name: 'explain_capabilities',
        description: 'List the AI Agent actions available to this editor in the current module/page context.'
            . ' Call this when the user asks what you can do, which tools exist, or how you can help.',
    )]
    public function execute(string $module = '', int $pageId = 0): string
    {
        $catalog = $this->actionCatalog->buildCatalog();
        $executable = $catalog['executable'];
        $locked = $catalog['locked'];
        $byName = [];
        foreach ($executable as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '' || $this->isHidden($name)) {
                continue;
            }
            $byName[$name] = $tool;
        }

        $ordered = [];
        foreach (self::PAGE_PREFERRED as $name) {
            if (isset($byName[$name])) {
                $ordered[$name] = $byName[$name];
                unset($byName[$name]);
            }
        }
        // Fill remaining: reads before writes.
        $reads = [];
        $writes = [];
        foreach ($byName as $name => $tool) {
            $severity = strtolower((string) ($tool['severity'] ?? 'read'));
            if ($severity === 'read') {
                $reads[$name] = $tool;
            } else {
                $writes[$name] = $tool;
            }
        }
        $candidates = array_merge($ordered, $reads, $writes);

        $lines = [];
        $lines[] = $pageId > 0 ? 'On this page I can help with:' : 'In this module I can help with:';

        $listed = 0;
        $shown = [];
        foreach ($candidates as $tool) {
            if ($listed >= self::MAX_LISTED) {
                break;
            }
            $name = (string) ($tool['name'] ?? '');
            $label = $this->shortLabel($tool);
            $severity = strtolower((string) ($tool['severity'] ?? ''));
            $suffix = match ($severity) {
                'write' => ' — I draft, you approve',
                'destructive' => ' — needs confirmation',
                default => '',
            };
            $lines[] = '- ' . $label . $suffix;
            $shown[] = $name;
            ++$listed;
        }

        $remaining = max(0, count($candidates) - $listed);
        if ($remaining > 0) {
            $lines[] = sprintf('…and %d more via / or a more specific ask.', $remaining);
        }
        if ($listed === 0) {
            $lines[] = '- (none — check entitlements / installed extensions)';
        }
        if ($locked !== []) {
            $lines[] = sprintf('%d actions need another extension or plan.', count($locked));
        }
        $lines[] = 'Tip: try a starter chip, or type / to pick a tool.';

        return json_encode([
            'ok' => true,
            'summary' => implode("\n", $lines),
            'executableCount' => count($executable),
            'listedCount' => $listed,
            'listedTools' => $shown,
            'lockedCount' => count($locked),
            'pageId' => $pageId,
            'module' => $module,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function shortLabel(array $tool): string
    {
        $label = trim((string) ($tool['editorLabel'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($tool['description'] ?? ''));
        }
        if ($label === '') {
            $label = (string) ($tool['name'] ?? 'tool');
        }
        // Keep the chat list scannable — drop vendor boilerplate.
        $label = preg_replace('/\s+using the configured AI provider.*$/i', '', $label) ?? $label;
        $label = preg_replace('/\s+and apply connected localization.*$/i', '', $label) ?? $label;
        if (strlen($label) > 72) {
            $label = rtrim(substr($label, 0, 69)) . '…';
        }

        return $label;
    }

    private function isHidden(string $name): bool
    {
        if (in_array($name, self::HIDDEN_EXACT, true)) {
            return true;
        }
        foreach (self::HIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
