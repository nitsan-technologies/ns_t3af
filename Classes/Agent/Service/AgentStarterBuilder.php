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

/**
 * Suggested actions ("starter chips") for where the editor is.
 *
 * - A chip is a request in the editor's language ("Generate SEO for this page"). Clicking it
 *   sends that text as a normal message, so the agent handles it like typed text: it picks
 *   the tools, prepares the change and the editor confirms it.
 * - Chips depend on the context (page, open record, file, workspace, module) and appear only
 *   when a tool for them is installed and permitted.
 * - Tools of other extensions that are not installed stay in the "locked" list (upsell).
 *
 * @internal
 */
final readonly class AgentStarterBuilder
{
    public const MAX_STARTERS = 4;

    /**
     * Starter id => [label key, tools (any one must be permitted)].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const STARTERS = [
        'recordImprove' => ['agent.starter.prompt.recordImprove', ['t3ai_apply_content_analysis', 'write_table']],
        'recordTranslate' => ['agent.starter.prompt.recordTranslate', ['t3ai_record_translate', 't3ai_translate_content']],
        'newsTranslate' => ['agent.starter.prompt.newsTranslate', ['t3ai_translate_news', 't3ai_record_translate']],
        'fileAltText' => ['agent.starter.prompt.fileAltText', ['t3aa_update_file_metadata']],
        'seo' => ['agent.starter.prompt.seo', ['t3ai_generate_all_seo']],
        'translatePage' => ['agent.starter.prompt.translatePage', ['t3ai_translate_page', 't3ai_translate_page_content', 't3ai_mass_translation_queue_add']],
        'accessibility' => ['agent.starter.prompt.accessibility', ['t3aa_accessibility_scan_run', 't3aa_accessibility_issues']],
        'summarizePage' => ['agent.starter.prompt.summarizePage', ['t3aa_summarize_content', 'content_list']],
        'addContent' => ['agent.starter.prompt.addContent', ['t3ai_create_content_element', 'write_table']],
        'createSubpage' => ['agent.starter.prompt.createSubpage', ['t3ai_create_page_simple', 'write_table']],
        'missingAltText' => ['agent.starter.prompt.missingAltText', ['t3aa_list_files_missing_alt_text']],
        'generateImage' => ['agent.starter.prompt.generateImage', ['t3ai_generate_image']],
        'redirectsToPage' => ['agent.starter.prompt.redirectsToPage', ['redirect_list']],
        'createRedirect' => ['agent.starter.prompt.createRedirect', ['redirect_create']],
        'failedTasks' => ['agent.starter.prompt.failedTasks', ['scheduler_list']],
        'workspaceChanges' => ['agent.starter.prompt.workspaceChanges', ['workspace_changes_list']],
        'pageTree' => ['agent.starter.prompt.pageTree', ['pages_tree']],
        'capabilities' => ['agent.starter.prompt.capabilities', ['explain_capabilities']],
    ];

    /**
     * Order of the starters per module family; the first ones that fit are shown.
     *
     * @var array<string, list<string>>
     */
    private const ORDER = [
        'web_layout' => ['recordImprove', 'recordTranslate', 'seo', 'translatePage', 'addContent', 'accessibility', 'summarizePage', 'createSubpage', 'workspaceChanges', 'capabilities'],
        'web_list' => ['newsTranslate', 'recordTranslate', 'recordImprove', 'seo', 'translatePage', 'createSubpage', 'workspaceChanges', 'capabilities'],
        'file' => ['fileAltText', 'missingAltText', 'generateImage', 'capabilities'],
        'redirects' => ['redirectsToPage', 'createRedirect', 'capabilities'],
        'scheduler' => ['failedTasks', 'capabilities'],
        'fallback' => ['seo', 'translatePage', 'addContent', 'createSubpage', 'missingAltText', 'workspaceChanges', 'pageTree', 'capabilities'],
    ];

    public function __construct(
        private PermittedActionProvider $permittedActionProvider,
        private AgentToolEditorLabelService $editorLabelService,
        private AgentTranslator $translator,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @return array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    public function build(array $context): array
    {
        $catalog = $this->permittedActionProvider->buildCatalog();
        $toolsByName = [];
        foreach ($catalog['executable'] as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name !== '') {
                $toolsByName[$name] = $tool;
            }
        }

        $executable = [];
        foreach (self::choose(array_keys($toolsByName), $context) as $choice) {
            $tool = $toolsByName[$choice['tool']];
            $prompt = $this->translator->translate($choice['labelKey']);
            $executable[] = [
                'name' => $choice['tool'],
                'starterId' => $choice['id'],
                'label' => $prompt,
                'prompt' => $prompt,
                'severity' => (string) ($tool['severity'] ?? ToolSeverity::Read->value),
                'ownerExtensionKey' => (string) ($tool['ownerExtensionKey'] ?? 'ns_t3af'),
            ];
        }

        return [
            'executable' => $executable,
            'locked' => $this->filterThirdPartyLockedStarters($catalog['locked']),
        ];
    }

    /**
     * The starters that fit the context, in order, each with the first permitted tool.
     *
     * @param list<string> $permittedTools
     * @param array<string, mixed> $context
     * @return list<array{id: string, labelKey: string, tool: string}>
     */
    public static function choose(array $permittedTools, array $context, int $limit = self::MAX_STARTERS): array
    {
        $permitted = array_flip($permittedTools);
        $chosen = [];
        foreach (self::ORDER[self::moduleFamily((string) ($context['module'] ?? ''))] as $id) {
            if (!self::fits($id, $context)) {
                continue;
            }
            [$labelKey, $tools] = self::STARTERS[$id];
            foreach ($tools as $tool) {
                if (isset($permitted[$tool])) {
                    $chosen[] = ['id' => $id, 'labelKey' => $labelKey, 'tool' => $tool];
                    break;
                }
            }
            if (count($chosen) >= $limit) {
                break;
            }
        }

        return $chosen;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function fits(string $id, array $context): bool
    {
        $pageId = (int) ($context['pageId'] ?? 0);
        $record = is_array($context['record'] ?? null) ? $context['record'] : [];
        $recordTable = (int) ($record['uid'] ?? 0) > 0 ? (string) ($record['table'] ?? '') : '';
        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        $siteLanguages = is_array($details['siteLanguages'] ?? null) ? $details['siteLanguages'] : [];

        return match ($id) {
            'recordImprove', 'recordTranslate' => $recordTable === 'tt_content',
            'newsTranslate' => $recordTable === 'tx_news_domain_model_news',
            'fileAltText' => in_array($recordTable, ['sys_file', 'sys_file_metadata'], true) || (int) ($context['fileUid'] ?? 0) > 0,
            'seo', 'accessibility', 'summarizePage', 'addContent', 'createSubpage', 'redirectsToPage' => $pageId > 0,
            // Only from the default language, and only when the site has other languages.
            'translatePage' => $pageId > 0 && (int) ($context['languageId'] ?? 0) === 0 && count($siteLanguages) > 1,
            'workspaceChanges' => (int) ($context['workspaceId'] ?? 0) > 0,
            default => true,
        };
    }

    private static function moduleFamily(string $module): string
    {
        $normalized = strtolower(trim($module));

        return match (true) {
            $normalized === '' => 'fallback',
            $normalized === 'web_layout' || str_contains($normalized, 'layout') => 'web_layout',
            $normalized === 'web_list' || str_contains($normalized, 'records') => 'web_list',
            str_starts_with($normalized, 'file') || $normalized === 'media_management' => 'file',
            str_contains($normalized, 'redirect') => 'redirects',
            str_contains($normalized, 'scheduler') => 'scheduler',
            default => 'fallback',
        };
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

        return array_map(function (array $tool): array {
            $tool['label'] = (string) ($tool['editorLabel'] ?? $this->editorLabelService->resolve($tool));
            unset($tool['action']);

            return $tool;
        }, array_slice($filtered, 0, 4));
    }
}
