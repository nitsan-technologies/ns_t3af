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

    public const DEFAULT_MIN_SIMILARITY = 0.0;

    public const DEFAULT_EMBEDDING_SOURCE = 'auto';

    public const CONVERSATION_SCOPES = ['page', 'module', 'user'];

    private const EXTENSION_KEY = 'ns_t3af';

    public const DEFAULT_HISTORY_TOKEN_BUDGET = 6000;

    /**
     * @var array<string, string>
     */
    private const FORM_TO_STORAGE = [
        'agentMaxReadToolsPerTurn' => 'agentMaxReadToolsPerTurn',
        'agentMaxWriteDraftsPerTurn' => 'agentMaxWriteDraftsPerTurn',
        'agentShowProviderThinking' => 'agentShowProviderThinking',
        'agentConversationRetentionDays' => 'agentConversationRetentionDays',
        'agentEmbeddingSource' => 'agentEmbeddingSource',
        'agentEmbeddingProvider' => 'agentEmbeddingProvider',
        'agentMinSimilarity' => 'agentMinSimilarity',
        'agentConversationScope' => 'agentConversationScope',
        'agentSessionListEnabled' => 'agentSessionListEnabled',
        'agentSessionListDefaultFilter' => 'agentSessionListDefaultFilter',
        'agentMaxSessionsPerScope' => 'agentMaxSessionsPerScope',
        'agentMaxSessionsPerUser' => 'agentMaxSessionsPerUser',
        'agentContinueAfterConfirm' => 'agentContinueAfterConfirm',
        'agentHistoryTokenBudget' => 'agentHistoryTokenBudget',
    ];

    public function __construct(
        private readonly ExtensionSettingsService $extensionSettingsService,
    ) {}

    /**
     * @return array<string, int|bool|string|float>
     */
    public function all(): array
    {
        $stored = $this->storedValues();

        return [
            'agentMaxReadToolsPerTurn' => (int) $stored['agentMaxReadToolsPerTurn'],
            'agentMaxWriteDraftsPerTurn' => (int) $stored['agentMaxWriteDraftsPerTurn'],
            'agentShowProviderThinking' => (int) $stored['agentShowProviderThinking'] === 1,
            'agentConversationRetentionDays' => (int) $stored['agentConversationRetentionDays'],
            'agentEmbeddingSource' => (string) $stored['agentEmbeddingSource'],
            'agentEmbeddingProvider' => (string) $stored['agentEmbeddingProvider'],
            'agentMinSimilarity' => (float) $stored['agentMinSimilarity'],
            'agentConversationScope' => (string) $stored['agentConversationScope'],
            'agentSessionListEnabled' => $stored['agentSessionListEnabled'] === '' || (int) $stored['agentSessionListEnabled'] === 1,
            'agentSessionListDefaultFilter' => (string) $stored['agentSessionListDefaultFilter'],
            'agentMaxSessionsPerScope' => $stored['agentMaxSessionsPerScope'] === '' ? 20 : (int) $stored['agentMaxSessionsPerScope'],
            'agentMaxSessionsPerUser' => $stored['agentMaxSessionsPerUser'] === '' ? 200 : (int) $stored['agentMaxSessionsPerUser'],
            'agentContinueAfterConfirm' => $stored['agentContinueAfterConfirm'] === '' || (int) $stored['agentContinueAfterConfirm'] === 1,
            'agentHistoryTokenBudget' => $stored['agentHistoryTokenBudget'] === '' ? self::DEFAULT_HISTORY_TOKEN_BUDGET : (int) $stored['agentHistoryTokenBudget'],
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

    public function getEmbeddingSource(): string
    {
        $value = strtolower(trim((string) ($this->all()['agentEmbeddingSource'] ?? self::DEFAULT_EMBEDDING_SOURCE)));

        return $value !== '' ? $value : self::DEFAULT_EMBEDDING_SOURCE;
    }

    public function getEmbeddingProvider(): string
    {
        return trim((string) ($this->all()['agentEmbeddingProvider'] ?? ''));
    }

    /**
     * Which conversation opens automatically: page (module + page), module, or user (latest anywhere).
     */
    public function getConversationScope(): string
    {
        $scope = strtolower(trim((string) ($this->all()['agentConversationScope'] ?? 'page')));

        return in_array($scope, self::CONVERSATION_SCOPES, true) ? $scope : 'page';
    }

    public function isSessionListEnabled(): bool
    {
        return ($this->all()['agentSessionListEnabled'] ?? true) === true;
    }

    /**
     * Initial filter of the session list: "current" (this page / module) or "all".
     */
    public function getSessionListDefaultFilter(): string
    {
        return ($this->all()['agentSessionListDefaultFilter'] ?? 'current') === 'all' ? 'all' : 'current';
    }

    /** 0 = unlimited */
    public function getMaxSessionsPerScope(): int
    {
        return max(0, (int) ($this->all()['agentMaxSessionsPerScope'] ?? 20));
    }

    /**
     * After the editor confirms or declines a card of a natural-language turn, the agent runs one
     * more turn so multi-step requests continue ("create the page, then add two headers").
     */
    public function isContinueAfterConfirmEnabled(): bool
    {
        return ($this->all()['agentContinueAfterConfirm'] ?? true) === true;
    }

    /**
     * Approximate tokens of earlier messages replayed to the model per turn (1 token ≈ 4 characters).
     */
    public function getHistoryTokenBudget(): int
    {
        return max(500, min(100000, (int) ($this->all()['agentHistoryTokenBudget'] ?? self::DEFAULT_HISTORY_TOKEN_BUDGET)));
    }

    /** 0 = unlimited */
    public function getMaxSessionsPerUser(): int
    {
        return max(0, (int) ($this->all()['agentMaxSessionsPerUser'] ?? 200));
    }

    public function getMinSimilarity(): float
    {
        return (float) ($this->all()['agentMinSimilarity'] ?? self::DEFAULT_MIN_SIMILARITY);
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
