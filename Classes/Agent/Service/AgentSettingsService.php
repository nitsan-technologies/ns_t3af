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

    public const DEFAULT_SHORTLIST_SIZE = 12;

    public const DEFAULT_MIN_SIMILARITY = 0.0;

    public const DEFAULT_EMBEDDING_SOURCE = 'auto';

    public const DEFAULT_TRANSFORMERS_MODEL = 'Xenova/all-MiniLM-L6-v2';

    private const EXTENSION_KEY = 'ns_t3af';

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
        'agentTransformersModel' => 'agentTransformersModel',
        'agentShortlistSize' => 'agentShortlistSize',
        'agentMinSimilarity' => 'agentMinSimilarity',
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
            'agentTransformersModel' => (string) $stored['agentTransformersModel'],
            'agentShortlistSize' => (int) $stored['agentShortlistSize'],
            'agentMinSimilarity' => (float) $stored['agentMinSimilarity'],
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

    public function getTransformersModel(): string
    {
        $value = trim((string) ($this->all()['agentTransformersModel'] ?? self::DEFAULT_TRANSFORMERS_MODEL));

        return $value !== '' ? $value : self::DEFAULT_TRANSFORMERS_MODEL;
    }

    public function getShortlistSize(): int
    {
        $size = (int) ($this->all()['agentShortlistSize'] ?? self::DEFAULT_SHORTLIST_SIZE);

        return $size > 0 ? $size : self::DEFAULT_SHORTLIST_SIZE;
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
