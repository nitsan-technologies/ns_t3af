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

use NITSAN\NsT3AF\Access\Dto\AgentToolPolicy;
use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Agent\Entitlement\EntitlementResolver;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Permitted tool catalog for the AI Agent (T4).
 *
 * @internal
 */
final readonly class PermittedActionProvider implements AgentActionCatalogInterface
{
    private const CORE_EXTENSION_KEY = 'ns_t3af';

    /**
     * Explicit denylist (also covered by DualMode∧¬Previewable Write, kept for clarity).
     *
     * @var list<string>
     */
    private const AGENT_HIDDEN_TOOL_NAMES = [
        't3ai_generate_meta_description',
        't3ai_generate_keywords',
        't3ai_generate_og_title',
        't3ai_generate_og_description',
        't3aa_get_file_for_metadata',
    ];

    public function __construct(
        private McpToolIntrospectorService $toolIntrospector,
        private EntitlementResolver $entitlementResolver,
        private AgentToolPlanResolver $toolPlanResolver,
        private AgentToolEditorLabelService $editorLabelService,
        private AgentTranslator $translator,
        private AgentGovernanceGuard $governanceGuard,
    ) {}

    /**
     * @return array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    public function buildCatalog(): array
    {
        $executable = [];
        $locked = [];
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $policy = $this->governanceGuard->agentToolPolicy($backendUser instanceof BackendUserAuthentication ? $backendUser : null);

        foreach ($this->toolIntrospector->listTools() as $tool) {
            if ($this->isHiddenFromAgent($tool)) {
                continue;
            }
            $entry = $this->normalizeToolEntry($tool, $policy);
            if (($entry['executable'] ?? false) === true) {
                $executable[] = $entry;
            } else {
                $locked[] = $entry;
            }
        }

        usort($executable, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        usort($locked, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return [
            'executable' => $executable,
            'locked' => $locked,
        ];
    }

    /**
     * DualMode Write without Previewable stays MCP-visible but agent-hidden.
     * Read DualMode (e.g. summarize) stays visible — native execute, no card.
     *
     * @param array<string, mixed> $tool
     */
    public function isHiddenFromAgent(array $tool): bool
    {
        if (($tool['agentHidden'] ?? false) === true) {
            return true;
        }

        $name = (string) ($tool['name'] ?? '');
        if (in_array($name, self::AGENT_HIDDEN_TOOL_NAMES, true)) {
            return true;
        }

        if (($tool['dualMode'] ?? false) !== true || ($tool['previewable'] ?? false) === true) {
            return false;
        }

        $severity = ToolSeverity::tryFromString((string) ($tool['severity'] ?? ''));

        // Read DualMode: native execute as a read — keep in catalog.
        return $severity !== ToolSeverity::Read;
    }

    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function normalizeToolEntry(array $tool, AgentToolPolicy $policy): array
    {
        $toolName = (string) ($tool['name'] ?? '');
        $ownerKey = (string) ($tool['ownerExtensionKey'] ?? self::CORE_EXTENSION_KEY);
        if ($ownerKey === '') {
            $ownerKey = self::CORE_EXTENSION_KEY;
        }

        $severity = ToolSeverity::tryFromString((string) ($tool['severity'] ?? ''));
        $lockReason = '';
        $executable = true;
        $lockKind = '';

        if ($toolName === 'file_upload') {
            return [
                'name' => $toolName,
                'description' => (string) ($tool['description'] ?? ''),
                'editorLabel' => $this->editorLabelService->resolve($tool),
                'severity' => $severity?->value,
                'severityLabel' => $severity?->label() ?? '',
                'ownerExtensionKey' => $ownerKey,
                'ownerLabel' => $this->formatOwnerLabel($ownerKey),
                'executable' => false,
                'lockReason' => $this->translator->translate('agent.tool.uploadViaComposer'),
                'lockKind' => 'composer',
            ];
        }

        if ($severity === null) {
            $executable = false;
            $lockKind = 'severity';
            $lockReason = $this->translator->translate('agent.tool.unclassified');
        } elseif ($policy->blocks($toolName, $severity)) {
            $executable = false;
            $lockKind = 'group';
            $lockReason = $this->translator->translate('agent.tool.blockedForGroup');
        } elseif (!$this->entitlementResolver->isExecutable($ownerKey)) {
            $executable = false;
            $lockKind = 'extension';
            $lockReason = $this->translator->translate('agent.tool.extensionUnavailable', [$this->formatOwnerLabel($ownerKey)]);
        } elseif (
            ($severity === ToolSeverity::Write || $severity === ToolSeverity::Destructive)
            && ($tool['previewable'] ?? false) !== true
            && !$this->toolPlanResolver->supportsPlanning((string) ($tool['name'] ?? ''))
        ) {
            $executable = false;
            $lockKind = 'plan';
            $lockReason = $this->translator->translate('agent.tool.planUnsupported');
        }

        return [
            'name' => (string) ($tool['name'] ?? ''),
            'description' => (string) ($tool['description'] ?? ''),
            'editorLabel' => $this->editorLabelService->resolve($tool),
            'severity' => $severity?->value,
            'severityLabel' => $severity?->label() ?? '',
            'ownerExtensionKey' => $ownerKey,
            'ownerLabel' => $this->formatOwnerLabel($ownerKey),
            'executable' => $executable,
            'lockReason' => $lockReason,
            'lockKind' => $lockKind,
            'intent' => is_array($tool['intent'] ?? null) ? $tool['intent'] : null,
            'contextHints' => is_array($tool['contextHints'] ?? null) ? $tool['contextHints'] : null,
            'dualMode' => ($tool['dualMode'] ?? false) === true,
            'previewable' => ($tool['previewable'] ?? false) === true,
        ];
    }

    private function formatOwnerLabel(string $ownerKey): string
    {
        if ($ownerKey === self::CORE_EXTENSION_KEY || $ownerKey === '') {
            return $this->translator->translate('agent.owner.core');
        }

        return $ownerKey;
    }

}
