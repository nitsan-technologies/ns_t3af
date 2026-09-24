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
use NITSAN\NsT3AF\Mcp\Exception\UnsupportedPlanException;
use NITSAN\NsT3AF\Mcp\Service\McpConfirmationPlanBuilder;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Generic draft plans for T3Planet satellite MCP write tools (t3ai, t3aa, t3cs, …).
 *
 * @internal
 */
final readonly class SatelliteToolPlanService
{
    public const PLAN_KIND_TOOL_CONFIRMATION = 'tool_confirmation';

    /**
     * @var list<string>
     */
    private const SATELLITE_PREFIXES = [
        't3ai_',
        't3aa_',
        't3cs_',
        't3as_',
        't3ac_',
        't3af_extended_',
    ];

    public function __construct(
        private DeclaredToolSeverityLookup $severityLookup,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
        private AgentTranslator $translator,
        private ?SiteFinder $siteFinder = null,
    ) {}

    public function supports(string $toolName): bool
    {
        if (!$this->isSatelliteTool($toolName)) {
            return false;
        }

        $severity = $this->severityLookup->severityFor($toolName);

        return $severity === ToolSeverity::Write || $severity === ToolSeverity::Destructive;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(string $toolName, array $arguments): ToolPlan
    {
        if (!$this->supports($toolName)) {
            throw new UnsupportedPlanException($this->translator->translate('agent.plan.satelliteUnsupported', [$toolName]));
        }

        $severity = $this->severityLookup->severityFor($toolName);
        $action = $severity === ToolSeverity::Destructive ? 'delete' : 'update';
        $displayArguments = $this->normalizeDisplayArguments($arguments);
        $summary = $this->buildSummary($toolName, $displayArguments);

        return $this->confirmationPlanBuilder->confirmation(
            $action,
            $toolName,
            '_tool',
            '',
            $summary,
            [
                'planKind' => self::PLAN_KIND_TOOL_CONFIRMATION,
                'summary' => $summary,
                'displayArguments' => $this->formatDisplayArguments($displayArguments),
                'arguments' => $arguments,
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function normalizeDisplayArguments(array $arguments): array
    {
        $display = $arguments;
        if (isset($display['pageId'])) {
            $pageId = (int) $display['pageId'];
            if ($pageId > 0) {
                if (($display['pid'] ?? null) === $pageId) {
                    unset($display['pid']);
                }
                if (($display['uid'] ?? null) === $pageId) {
                    unset($display['uid']);
                }
            }
        }

        return array_filter(
            $display,
            static fn(mixed $value): bool => $value !== '' && $value !== null && $value !== [],
        );
    }

    /**
     * Editor-facing rows: translated parameter names (`agent.arg.<key>`) and names instead of ids
     * for pages and languages ("„Home“ [1]", "German [1]").
     *
     * @param array<string, mixed> $arguments
     * @return list<array{key: string, value: string, label?: string}>
     */
    private function formatDisplayArguments(array $arguments): array
    {
        $pageId = (int) ($arguments['pageId'] ?? 0);
        $rows = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $row = [
                'key' => $key,
                'value' => $this->formatDisplayValue($key, $value, $pageId),
            ];
            $label = $this->translator->translate('agent.arg.' . $key);
            if ($label !== 'agent.arg.' . $key && $label !== '') {
                $row['label'] = $label;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function formatDisplayValue(string $key, mixed $value, int $pageId): string
    {
        $list = is_array($value) ? array_values($value) : [$value];
        $ids = array_map(static fn(mixed $item): int => is_numeric($item) ? (int) $item : 0, $list);

        if (is_bool($value)) {
            return $this->translator->translate($value ? 'agent.arg.yes' : 'agent.arg.no');
        }

        return match ($key) {
            'pageId', 'pageIds' => implode(', ', array_map(fn(int $uid): string => $this->pageLabel($uid), $ids)),
            'languageUid', 'targetLanguageUid', 'languageUids' => implode(', ', array_map(fn(int $uid): string => $this->languageLabel($uid, $pageId), $ids)),
            default => $this->formatArgumentValue($value),
        };
    }

    private function pageLabel(int $uid): string
    {
        if ($uid <= 0) {
            return (string) $uid;
        }
        try {
            $title = trim((string) (BackendUtility::getRecord('pages', $uid, 'title')['title'] ?? ''));
        } catch (\Throwable) {
            $title = '';
        }

        return $title !== '' ? sprintf('„%s“ [%d]', $title, $uid) : (string) $uid;
    }

    private function languageLabel(int $uid, int $pageId): string
    {
        if ($uid < 0) {
            return $this->translator->translate('agent.arg.allLanguages');
        }
        if ($this->siteFinder === null) {
            return (string) $uid;
        }
        try {
            $sites = $pageId > 0 ? [$this->siteFinder->getSiteByPageId($pageId)] : $this->siteFinder->getAllSites();
            foreach ($sites as $site) {
                foreach ($site->getAllLanguages() as $language) {
                    if ($language->getLanguageId() === $uid) {
                        return sprintf('%s [%d]', $language->getTitle(), $uid);
                    }
                }
            }
        } catch (\Throwable) {
        }

        return (string) $uid;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildSummary(string $toolName, array $arguments): string
    {
        $pageId = (int) ($arguments['pageId'] ?? 0);
        $pageHint = $pageId > 0 ? $this->translator->translate('agent.plan.pageHint', [$this->pageLabel($pageId)]) : '';

        $labelKey = match ($toolName) {
            't3ai_generate_all_seo' => 'agent.plan.generateAllSeo',
            't3ai_generate_meta_description' => 'agent.plan.generateMetaDescription',
            't3ai_translate_content' => 'agent.plan.translateContent',
            't3ai_translate_news' => 'agent.plan.translateNews',
            't3aa_update_file_metadata' => 'agent.plan.updateFileMetadata',
            't3cs_save_datasource' => 'agent.plan.saveDatasource',
            't3cs_sync_datasource' => 'agent.plan.syncDatasource',
            default => 'agent.plan.runTool',
        };

        if ($labelKey === 'agent.plan.runTool') {
            // Any other child tool: its editor label ("Translate the whole page") instead of "Run this tool".
            $toolLabel = $this->translator->translate('agent.tool.label.' . $toolName);
            if ($toolLabel !== 'agent.tool.label.' . $toolName && $toolLabel !== '') {
                return $this->translator->translate('agent.plan.namedTool', [$toolLabel, $pageHint]);
            }
        }

        return $this->translator->translate($labelKey, [$pageHint]);
    }

    private function formatArgumentValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    private function isSatelliteTool(string $toolName): bool
    {
        foreach (self::SATELLITE_PREFIXES as $prefix) {
            if (str_starts_with($toolName, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
