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

use NITSAN\NsT3AF\Settings\ExtensionSettingsBootstrapReader;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;

/**
 * AI Agent module settings persisted in tx_nst3af_extension_setting (global).
 */
final class AgentSettingsService
{
    public const DEFAULT_CONVERSATION_RETENTION_DAYS = 90;

    private const EXTENSION_KEY = 'ns_t3af';

    /**
     * @var array<string, string>
     */
    private const FORM_TO_STORAGE = [
        'agentMaxReadToolsPerTurn' => 'agentMaxReadToolsPerTurn',
        'agentMaxWriteDraftsPerTurn' => 'agentMaxWriteDraftsPerTurn',
        'agentShowProviderThinking' => 'agentShowProviderThinking',
        'agentConversationRetentionDays' => 'agentConversationRetentionDays',
    ];

    public function __construct(
        private readonly ExtensionSettingsService $extensionSettingsService,
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function all(): array
    {
        $stored = $this->storedValues();

        return [
            'agentMaxReadToolsPerTurn' => (int) $stored['agentMaxReadToolsPerTurn'],
            'agentMaxWriteDraftsPerTurn' => (int) $stored['agentMaxWriteDraftsPerTurn'],
            'agentShowProviderThinking' => (int) $stored['agentShowProviderThinking'] === 1,
            'agentConversationRetentionDays' => (int) $stored['agentConversationRetentionDays'],
        ];
    }

    public function getMaxReadToolsPerTurn(): int
    {
        return max(1, (int) ($this->all()['agentMaxReadToolsPerTurn'] ?? 5));
    }

    public function getMaxWriteDraftsPerTurn(): int
    {
        return max(0, (int) ($this->all()['agentMaxWriteDraftsPerTurn'] ?? 2));
    }

    public function isProviderThinkingVisible(): bool
    {
        return ($this->all()['agentShowProviderThinking'] ?? true) === true;
    }

    public function getConversationRetentionDays(): int
    {
        $days = (int) ($this->all()['agentConversationRetentionDays'] ?? self::DEFAULT_CONVERSATION_RETENTION_DAYS);

        return $days > 0 ? $days : self::DEFAULT_CONVERSATION_RETENTION_DAYS;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        $values = [];
        foreach (self::FORM_TO_STORAGE as $inputKey => $storageKey) {
            if (!array_key_exists($inputKey, $input)) {
                continue;
            }

            $values[$storageKey] = (string) $input[$inputKey];
        }

        if ($values === []) {
            return;
        }

        $this->extensionSettingsService->mergeGlobal(self::EXTENSION_KEY, $values);
    }

    /**
     * @return array<string, string>
     */
    private function storedValues(): array
    {
        $defaults = ExtensionSettingsBootstrapReader::getDefaults(self::EXTENSION_KEY);
        $stored = $this->extensionSettingsService->getAllIgnorePid(self::EXTENSION_KEY);

        $values = [];
        foreach (self::FORM_TO_STORAGE as $storageKey) {
            $values[$storageKey] = (string) ($stored[$storageKey] ?? $defaults[$storageKey] ?? '');
        }

        return $values;
    }
}
