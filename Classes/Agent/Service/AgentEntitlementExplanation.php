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
use NITSAN\NsT3AF\Utility\ModuleTabUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * In-conversation explanation when a locked tool is activated (T18).
 *
 * @internal
 */
final readonly class AgentEntitlementExplanation
{
    private const LOCK_KIND_EXTENSION = 'extension';
    private const LOCK_KIND_PLAN = 'plan';
    private const LOCK_KIND_SEVERITY = 'severity';
    private const LOCK_KIND_COMPOSER = 'composer';

    public function __construct(
        private EntitlementResolver $entitlementResolver,
        private ModuleTabUtility $moduleTabUtility,
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * @param array<string, mixed> $tool
     */
    public function buildMessage(array $tool): string
    {
        return match ($this->resolveLockKind($tool)) {
            self::LOCK_KIND_PLAN => $this->buildPlanLockedMessage($tool),
            self::LOCK_KIND_SEVERITY => $this->buildSeverityLockedMessage($tool),
            self::LOCK_KIND_COMPOSER => $this->buildComposerLockedMessage($tool),
            default => $this->buildExtensionLockedMessage($tool),
        };
    }

    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    public function buildMeta(array $tool): array
    {
        $ownerKey = (string) ($tool['ownerExtensionKey'] ?? 'ns_t3af');
        $lockKind = $this->resolveLockKind($tool);

        if ($lockKind === self::LOCK_KIND_PLAN || $lockKind === self::LOCK_KIND_SEVERITY || $lockKind === self::LOCK_KIND_COMPOSER) {
            return [
                'type' => 'info',
                'tool' => (string) ($tool['name'] ?? ''),
                'owner' => $ownerKey,
                'lockKind' => $lockKind,
            ];
        }

        return [
            'type' => 'locked',
            'tool' => (string) ($tool['name'] ?? ''),
            'owner' => $ownerKey,
            'ownerLabel' => (string) ($tool['ownerLabel'] ?? $ownerKey),
            'toolCount' => $this->entitlementResolver->getToolCount($ownerKey),
            'settingsHref' => $this->settingsHref(),
            'settingsLabel' => $this->translate('agent.modal.settings'),
            'lockKind' => $lockKind,
        ];
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function buildExtensionLockedMessage(array $tool): string
    {
        $ownerKey = (string) ($tool['ownerExtensionKey'] ?? 'ns_t3af');
        $toolName = (string) ($tool['name'] ?? '');
        $ownerLabel = (string) ($tool['ownerLabel'] ?? $ownerKey);
        $toolCount = $this->entitlementResolver->getToolCount($ownerKey);
        $lockReason = trim((string) ($tool['lockReason'] ?? ''));

        $parts = [
            $this->translate('agent.entitlement.lockedLead', [$toolName, $ownerLabel]),
        ];

        if ($toolCount > 0) {
            $parts[] = $this->translate('agent.entitlement.toolCount', [$toolCount, $ownerLabel]);
        }

        if ($lockReason !== '') {
            $parts[] = $lockReason;
        }

        $parts[] = $this->translate('agent.entitlement.settingsHint', [$this->settingsHref()]);

        return implode("\n\n", $parts);
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function buildPlanLockedMessage(array $tool): string
    {
        $toolName = (string) ($tool['name'] ?? '');
        $severityLabel = strtolower((string) ($tool['severityLabel'] ?? $tool['severity'] ?? 'write'));

        return $this->translate('agent.entitlement.planLockedLead', [$toolName, $severityLabel]);
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function buildComposerLockedMessage(array $tool): string
    {
        $toolName = (string) ($tool['name'] ?? '');
        $lockReason = trim((string) ($tool['lockReason'] ?? ''));

        $parts = [$this->translate('agent.entitlement.composerLockedLead', [$toolName])];
        if ($lockReason !== '') {
            $parts[] = $lockReason;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function buildSeverityLockedMessage(array $tool): string
    {
        $toolName = (string) ($tool['name'] ?? '');
        $lockReason = trim((string) ($tool['lockReason'] ?? ''));

        $parts = [$this->translate('agent.entitlement.severityLockedLead', [$toolName])];
        if ($lockReason !== '') {
            $parts[] = $lockReason;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function resolveLockKind(array $tool): string
    {
        $lockKind = trim((string) ($tool['lockKind'] ?? ''));
        if ($lockKind !== '') {
            return $lockKind;
        }

        return self::LOCK_KIND_EXTENSION;
    }

    private function settingsHref(): string
    {
        $route = $this->moduleTabUtility->routeFor('aiAgent') ?? 't3af_dashboard.ai_agent';

        return (string) $this->uriBuilder->buildUriFromRoute($route);
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_be.xlf:' . $key;
        $value = $languageService instanceof LanguageService
            ? (string) $languageService->sL($label)
            : $key;

        if ($value === '' || $value === $label) {
            $value = $key;
        }

        if ($arguments === []) {
            return $value;
        }

        return sprintf($value, ...array_map(static fn(int|string $argument): string => (string) $argument, $arguments));
    }
}
