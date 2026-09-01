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

use NITSAN\NsT3AF\Agent\Entitlement\EntitlementResolver;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Permitted tool catalog for the AI Agent (T4).
 *
 * @internal
 */
final readonly class PermittedActionProvider
{
    private const CORE_EXTENSION_KEY = 'ns_t3af';

    public function __construct(
        private McpToolIntrospectorService $toolIntrospector,
        private EntitlementResolver $entitlementResolver,
        private AgentToolPlanResolver $toolPlanResolver,
    ) {}

    /**
     * @return array{executable: list<array<string, mixed>>, locked: list<array<string, mixed>>}
     */
    public function buildCatalog(): array
    {
        $executable = [];
        $locked = [];

        foreach ($this->toolIntrospector->listTools() as $tool) {
            $entry = $this->normalizeToolEntry($tool);
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
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function normalizeToolEntry(array $tool): array
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
                'severity' => $severity?->value,
                'severityLabel' => $severity?->label() ?? '',
                'ownerExtensionKey' => $ownerKey,
                'ownerLabel' => $this->formatOwnerLabel($ownerKey),
                'executable' => false,
                'lockReason' => $this->translate('agent.tool.uploadViaComposer'),
                'lockKind' => 'composer',
            ];
        }

        if ($severity === null) {
            $executable = false;
            $lockKind = 'severity';
            $lockReason = $this->translate('agent.tool.unclassified');
        } elseif (!$this->entitlementResolver->isExecutable($ownerKey)) {
            $executable = false;
            $lockKind = 'extension';
            $lockReason = $this->translate('agent.tool.extensionUnavailable', [$this->formatOwnerLabel($ownerKey)]);
        } elseif (
            ($severity === ToolSeverity::Write || $severity === ToolSeverity::Destructive)
            && !$this->toolPlanResolver->supportsPlanning((string) ($tool['name'] ?? ''))
        ) {
            $executable = false;
            $lockKind = 'plan';
            $lockReason = $this->translate('agent.tool.planUnsupported');
        }

        return [
            'name' => (string) ($tool['name'] ?? ''),
            'description' => (string) ($tool['description'] ?? ''),
            'severity' => $severity?->value,
            'severityLabel' => $severity?->label() ?? '',
            'ownerExtensionKey' => $ownerKey,
            'ownerLabel' => $this->formatOwnerLabel($ownerKey),
            'executable' => $executable,
            'lockReason' => $lockReason,
            'lockKind' => $lockKind,
            'intent' => is_array($tool['intent'] ?? null) ? $tool['intent'] : null,
            'contextHints' => is_array($tool['contextHints'] ?? null) ? $tool['contextHints'] : null,
        ];
    }

    private function formatOwnerLabel(string $ownerKey): string
    {
        if ($ownerKey === self::CORE_EXTENSION_KEY || $ownerKey === '') {
            return $this->translate('agent.owner.core');
        }

        return $ownerKey;
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        return $languageService->sL('LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key)
            ?: $key;
    }
}
