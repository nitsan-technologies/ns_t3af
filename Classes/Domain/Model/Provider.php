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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Domain\Model;

use NITSAN\NsT3AF\Provider\Capability;

/**
 * Read-only view of one row in `tx_nst3af_provider`.
 *
 * Hydrated by {@see \NITSAN\NsT3AF\Domain\Repository\ProviderRepository}
 * via {@see self::fromRow()}. Never instantiated by child extensions —
 * consume through {@see \NITSAN\NsT3AF\Api\AiServiceInterface} (Phase 3).
 *
 * @api Returned by repository / service surface; field names are part of the
 *      semver-stable contract.
 */
final readonly class Provider
{
    /**
     * Machine adapter id for the built-in OpenAI-compatible HTTP provider (**Custom / Other**).
     */
    public const ADAPTER_OPENAI_COMPATIBLE = 'nst3af.openai_compatible';

    /** Symfony AI bridge for local / remote Ollama (no API key). */
    public const ADAPTER_SYMFONY_OLLAMA = 'symfony.ollama';

    /** Persisted probe status before the first connection test. */
    public const LAST_STATUS_UNKNOWN = 'unknown';

    public static function adapterRequiresEndpoint(string $adapterType): bool
    {
        return $adapterType === self::ADAPTER_OPENAI_COMPATIBLE
            || $adapterType === self::ADAPTER_SYMFONY_OLLAMA;
    }

    public static function adapterRequiresApiKey(string $adapterType): bool
    {
        return $adapterType !== self::ADAPTER_SYMFONY_OLLAMA;
    }

    public static function normalizeLastStatus(string $lastStatus): string
    {
        $trimmed = trim($lastStatus);

        return $trimmed !== '' ? $trimmed : self::LAST_STATUS_UNKNOWN;
    }

    /**
     * @param list<string> $capabilities Subset of {@see Capability::ALL}.
     * @param list<int>    $beGroups     UIDs of allowed `be_groups`. Empty = all.
     */
    public function __construct(
        public int $uid,
        public int $pid,
        public string $identifier,
        public string $title,
        public string $adapterType,
        public string $endpointUrl,
        public string $apiKeyCipher,
        public string $modelId,
        public string $embeddingModelId,
        public array $capabilities,
        public float $temperature,
        public string $systemPrompt,
        public bool $isDefault,
        public int $priority,
        public int $lastUsedAt,
        public string $lastStatus,
        public int $lastStatusAt,
        public string $lastStatusMessage,
        public array $beGroups = [],
        public bool $isEnabled = true,
        public bool $enabledForDashboard = true,
        public float $pricingInputPer1m = 0.0,
        public float $pricingOutputPer1m = 0.0,
        public string $pricingCurrency = 'USD',
        public int $retentionDaysOverride = 0,
        public string $costCenter = '',
        public string $privacyLevel = 'standard',
        public bool $noRerouting = false,
    ) {}

    /**
     * Maps deprecated stored adapter ids to their replacements (DB rows may lag until re-saved).
     */
    public static function normalizeAdapterType(string $adapterType): string
    {
        if ($adapterType === 'symfony.openai_compatible') {
            return self::ADAPTER_OPENAI_COMPATIBLE;
        }
        if (str_starts_with($adapterType, 'symfony.')) {
            $vendor = substr($adapterType, strlen('symfony.'));

            return 'symfony.' . str_replace('-', '', $vendor);
        }

        return $adapterType;
    }

    /**
     * Build a Provider from a raw DB row (associative).
     *
     * Tolerant by design: missing keys fall back to type-appropriate defaults so
     * partial fixtures and migrations don't blow up. Capability column is
     * normalised through {@see Capability::fromCsv()}.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: (int) ($row['uid'] ?? 0),
            pid: (int) ($row['pid'] ?? 0),
            identifier: (string) ($row['identifier'] ?? ''),
            title: (string) ($row['title'] ?? ''),
            adapterType: self::normalizeAdapterType((string) ($row['adapter_type'] ?? '')),
            endpointUrl: (string) ($row['endpoint_url'] ?? ''),
            apiKeyCipher: (string) ($row['api_key'] ?? ''),
            modelId: (string) ($row['model_id'] ?? ''),
            embeddingModelId: (string) ($row['embedding_model_id'] ?? ''),
            capabilities: Capability::fromCsv((string) ($row['capabilities'] ?? '')),
            temperature: (float) ($row['temperature'] ?? 0.7),
            systemPrompt: (string) ($row['system_prompt'] ?? ''),
            isDefault: (bool) ($row['is_default'] ?? false),
            priority: (int) ($row['priority'] ?? 50),
            lastUsedAt: (int) ($row['last_used_at'] ?? 0),
            lastStatus: self::normalizeLastStatus((string) ($row['last_status'] ?? '')),
            lastStatusAt: (int) ($row['last_status_at'] ?? 0),
            lastStatusMessage: (string) ($row['last_status_message'] ?? ''),
            beGroups: self::splitIntList((string) ($row['be_groups'] ?? '')),
            isEnabled: (bool) ($row['is_enabled'] ?? true),
            enabledForDashboard: (bool) ($row['enabled_for_dashboard'] ?? true),
            pricingInputPer1m: (float) ($row['pricing_input_per_1m'] ?? 0.0),
            pricingOutputPer1m: (float) ($row['pricing_output_per_1m'] ?? 0.0),
            pricingCurrency: (string) ($row['pricing_currency'] ?? 'USD'),
            retentionDaysOverride: (int) ($row['retention_days_override'] ?? 0),
            costCenter: (string) ($row['cost_center'] ?? ''),
            privacyLevel: (string) ($row['privacy_level'] ?? 'standard'),
            noRerouting: (bool) ($row['no_rerouting'] ?? false),
        );
    }

    /**
     * Whether the provider advertises a given capability.
     *
     * @param string $capability One of the {@see Capability} constants.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * Model id used for embedding calls when no per-call override is set.
     */
    public function effectiveEmbeddingModel(): string
    {
        return $this->embeddingModelId !== '' ? $this->embeddingModelId : $this->modelId;
    }

    /**
     * Clone with a different stored credential blob (encrypted ciphertext).
     */
    public function withApiKeyCipher(string $apiKeyCipher): self
    {
        return new self(
            uid: $this->uid,
            pid: $this->pid,
            identifier: $this->identifier,
            title: $this->title,
            adapterType: $this->adapterType,
            endpointUrl: $this->endpointUrl,
            apiKeyCipher: $apiKeyCipher,
            modelId: $this->modelId,
            embeddingModelId: $this->embeddingModelId,
            capabilities: $this->capabilities,
            temperature: $this->temperature,
            systemPrompt: $this->systemPrompt,
            isDefault: $this->isDefault,
            priority: $this->priority,
            lastUsedAt: $this->lastUsedAt,
            lastStatus: $this->lastStatus,
            lastStatusAt: $this->lastStatusAt,
            lastStatusMessage: $this->lastStatusMessage,
            beGroups: $this->beGroups,
            isEnabled: $this->isEnabled,
            enabledForDashboard: $this->enabledForDashboard,
            pricingInputPer1m: $this->pricingInputPer1m,
            pricingOutputPer1m: $this->pricingOutputPer1m,
            pricingCurrency: $this->pricingCurrency,
            retentionDaysOverride: $this->retentionDaysOverride,
            costCenter: $this->costCenter,
            privacyLevel: $this->privacyLevel,
            noRerouting: $this->noRerouting,
        );
    }

    /**
     * @return list<int>
     */
    private static function splitIntList(string $csv): array
    {
        if ($csv === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $csv));
        $ints = array_map('intval', array_filter($parts, static fn(string $v): bool => $v !== ''));

        return array_values($ints);
    }
}
