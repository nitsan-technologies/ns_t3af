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
use NITSAN\NsT3AF\Mcp\Service\McpToolSeverityResolver;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;

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
        private McpToolSeverityResolver $severityResolver,
        private McpConfirmationPlanBuilder $confirmationPlanBuilder,
    ) {}

    public function supports(string $toolName): bool
    {
        if (!$this->isSatelliteTool($toolName)) {
            return false;
        }

        $severity = $this->severityResolver->resolveForToolName($toolName);

        return $severity === ToolSeverity::Write || $severity === ToolSeverity::Destructive;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(string $toolName, array $arguments): ToolPlan
    {
        if (!$this->supports($toolName)) {
            throw new UnsupportedPlanException('Satellite tool does not support planning: ' . $toolName);
        }

        $severity = $this->severityResolver->resolveForToolName($toolName);
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
     * @param array<string, mixed> $arguments
     * @return list<array{key: string, value: string}>
     */
    private function formatDisplayArguments(array $arguments): array
    {
        $rows = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'value' => $this->formatArgumentValue($value),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildSummary(string $toolName, array $arguments): string
    {
        $pageId = (int) ($arguments['pageId'] ?? 0);
        $pageHint = $pageId > 0 ? ' for page ' . $pageId : '';

        return match ($toolName) {
            't3ai_generate_all_seo' => 'Generate and apply all SEO metadata' . $pageHint . '.',
            't3ai_generate_meta_description' => 'Generate a meta description' . $pageHint . '.',
            't3ai_translate_content' => 'Translate page content' . $pageHint . '.',
            't3ai_translate_news' => 'Translate a news record' . $pageHint . '.',
            't3aa_update_file_metadata' => 'Update file metadata (alt text, title).',
            't3cs_save_datasource' => 'Save a content source configuration.',
            't3cs_sync_datasource' => 'Sync a content source.',
            default => 'Run this tool' . $pageHint . '.',
        };
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
