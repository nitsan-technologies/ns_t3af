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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Model\ModelInfo;
use NITSAN\NsT3AF\Provider\Model\SymfonyAiCatalogReader;

/**
 * Preset AI vendors shown in setup wizard step 3 (own API keys mode).
 *
 * @internal
 */
final class WizardProviderCatalog
{
    /**
     * @var list<array{
     *     id: string,
     *     adapterType: string,
     *     identifier: string,
     *     displayName: string,
     *     defaultModel: string,
     *     badgeTone: string,
     *     titleKey: string,
     *     badgeKey: string,
     *     modelsKey: string,
     *     keyUrlKey: string,
     *     keyUrlHref: string,
     *     keyUrlHost: string,
     *     modelOptions: list<string>,
     *     capabilities: list<string>
     * }>
     */
    private const DEFINITIONS = [
        [
            'id' => 'openai',
            'adapterType' => 'symfony.openai',
            'identifier' => 'openai',
            'displayName' => 'OpenAI',
            'defaultModel' => 'gpt-4o',
            'badgeTone' => 'blue',
            'titleKey' => 'wizard.step3.catalog.openai.title',
            'badgeKey' => 'wizard.step3.catalog.openai.badge',
            'modelsKey' => 'wizard.step3.catalog.openai.models',
            'keyUrlKey' => 'wizard.step4.keyUrl.openai',
            'keyUrlHref' => 'https://platform.openai.com',
            'keyUrlHost' => 'platform.openai.com',
            'modelOptions' => ['gpt-4o-mini', 'gpt-4o'],
            'capabilities' => [Capability::CHAT, Capability::STREAMING, Capability::TOOL_USE, Capability::EMBEDDINGS],
        ],
        [
            'id' => 'anthropic',
            'adapterType' => 'symfony.anthropic',
            'identifier' => 'anthropic',
            'displayName' => 'Anthropic',
            'defaultModel' => 'claude-sonnet-4-5-20250929',
            'badgeTone' => 'purple',
            'titleKey' => 'wizard.step3.catalog.anthropic.title',
            'badgeKey' => 'wizard.step3.catalog.anthropic.badge',
            'modelsKey' => 'wizard.step3.catalog.anthropic.models',
            'keyUrlKey' => 'wizard.step4.keyUrl.anthropic',
            'keyUrlHref' => 'https://console.anthropic.com',
            'keyUrlHost' => 'console.anthropic.com',
            'modelOptions' => ['claude-haiku-4-5-20251001', 'claude-sonnet-4-5-20250929', 'claude-3-7-sonnet-20250219'],
            'capabilities' => [Capability::CHAT, Capability::STREAMING, Capability::TOOL_USE],
        ],
        [
            'id' => 'gemini',
            'adapterType' => 'symfony.gemini',
            'identifier' => 'gemini',
            'displayName' => 'Google Gemini',
            'defaultModel' => 'gemini-2.5-pro',
            'badgeTone' => 'green',
            'titleKey' => 'wizard.step3.catalog.gemini.title',
            'badgeKey' => 'wizard.step3.catalog.gemini.badge',
            'modelsKey' => 'wizard.step3.catalog.gemini.models',
            'keyUrlKey' => 'wizard.step4.keyUrl.gemini',
            'keyUrlHref' => 'https://aistudio.google.com',
            'keyUrlHost' => 'aistudio.google.com',
            'modelOptions' => ['gemini-2.5-flash', 'gemini-2.5-pro'],
            'capabilities' => [Capability::CHAT, Capability::STREAMING, Capability::VISION],
        ],
        [
            'id' => 'ollama',
            'adapterType' => Provider::ADAPTER_SYMFONY_OLLAMA,
            'identifier' => 'ollama',
            'displayName' => 'Ollama (Local)',
            'defaultModel' => 'llama3.2',
            'badgeTone' => 'orange',
            'titleKey' => 'wizard.step3.catalog.ollama.title',
            'badgeKey' => 'wizard.step3.catalog.ollama.badge',
            'modelsKey' => 'wizard.step3.catalog.ollama.models',
            'keyUrlKey' => 'wizard.step4.keyUrl.ollama',
            'keyUrlHref' => '',
            'keyUrlHost' => '',
            'modelOptions' => ['llama3.2', 'mistral'],
            'capabilities' => [Capability::CHAT, Capability::STREAMING],
        ],
    ];

    public function __construct(
        private readonly ProviderRepositoryInterface $repository,
        private readonly AdapterRegistry $adapters,
        private readonly SymfonyAiCatalogReader $catalogReader,
    ) {}

    public function adapterDisplayLabel(string $adapterType): string
    {
        $normalized = Provider::normalizeAdapterType(trim($adapterType));
        if ($normalized === '') {
            return '';
        }
        if ($this->adapters->has($normalized)) {
            return $this->adapters->get($normalized)->getDisplayName();
        }
        if (str_starts_with($normalized, 'symfony.')) {
            return ucfirst(substr($normalized, 8));
        }

        return $normalized;
    }

    /**
     * @param callable(string): string $translate Module label resolver (`wizard.step3.catalog.*`).
     *
     * @return list<array{
     *     id: string,
     *     adapterType: string,
     *     identifier: string,
     *     defaultModel: string,
     *     badgeTone: string,
     *     title: string,
     *     badge: string,
     *     models: string,
     *     modelOptions: list<string>,
     *     keyUrl: string,
     *     keyUrlHref: string,
     *     keyUrlHost: string,
     *     requiresApiKey: bool,
     *     adapterAvailable: bool
     * }>
     */
    public function listForWizard(callable $translate): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $def) {
            $models = $this->resolveWizardModels(
                $def['adapterType'],
                $def['defaultModel'],
                $def['modelOptions'],
            );
            $rows[] = [
                'id' => $def['id'],
                'adapterType' => $def['adapterType'],
                'identifier' => $def['identifier'],
                'defaultModel' => $models['defaultModel'],
                'badgeTone' => $def['badgeTone'],
                'title' => $translate($def['titleKey']),
                'badge' => $translate($def['badgeKey']),
                'models' => $translate($def['modelsKey']),
                'modelOptions' => $models['modelOptions'],
                'keyUrl' => $translate($def['keyUrlKey']),
                'keyUrlHref' => $def['keyUrlHref'],
                'keyUrlHost' => $def['keyUrlHost'],
                'requiresApiKey' => Provider::adapterRequiresApiKey($def['adapterType']),
                'adapterAvailable' => $this->adapters->has($def['adapterType']),
            ];
        }

        return $rows;
    }

    /**
     * Reuses an incomplete wizard draft at the given site root when present; otherwise
     * creates a new provider row with a unique identifier for that storage pid.
     */
    public function ensureProviderUid(string $catalogId, string $modelId = '', int $storagePid = 0): ?int
    {
        $def = $this->definition($catalogId);
        if ($def === null || !$this->adapters->has($def['adapterType'])) {
            return null;
        }

        if ($storagePid <= 0) {
            return null;
        }
        $existingDraft = $this->repository->findReusableWizardDraft($storagePid, $def['adapterType']);
        if ($existingDraft !== null) {
            return $existingDraft->uid;
        }

        $endpoint = '';
        if (Provider::adapterRequiresEndpoint($def['adapterType'])) {
            $endpoint = trim($this->adapters->get($def['adapterType'])->getDefaultEndpoint());
        }

        $models = $this->resolveWizardModels(
            $def['adapterType'],
            $def['defaultModel'],
            $def['modelOptions'],
        );
        $resolvedModel = trim($modelId) !== '' ? trim($modelId) : $models['defaultModel'];

        return $this->repository->save(0, [
            'pid' => $storagePid,
            'identifier' => $this->allocateIdentifier($def['identifier'], $storagePid),
            'title' => $def['displayName'],
            'adapter_type' => $def['adapterType'],
            'endpoint_url' => $endpoint,
            'api_key' => '',
            'model_id' => $resolvedModel,
            'capabilities' => implode(',', $def['capabilities']),
            'temperature' => 0.7,
            'system_prompt' => '',
            'is_default' => 0,
            'priority' => 50,
            'last_used_at' => 0,
            'last_status' => Provider::LAST_STATUS_UNKNOWN,
            'last_status_at' => 0,
            'last_status_message' => Provider::LAST_STATUS_UNKNOWN,
            'be_groups' => '',
            'is_enabled' => 1,
            'enabled_for_dashboard' => 1,
            'pricing_input_per_1m' => 0,
            'pricing_output_per_1m' => 0,
            'pricing_currency' => 'USD',
            'retention_days_override' => 0,
            'cost_center' => '',
            'hidden' => 0,
            'deleted' => 0,
        ]);
    }

    /**
     * @return array{
     *     id: string,
     *     adapterType: string,
     *     identifier: string,
     *     displayName: string,
     *     defaultModel: string,
     *     badgeTone: string,
     *     titleKey: string,
     *     badgeKey: string,
     *     modelsKey: string,
     *     modelOptions: list<string>,
     *     capabilities: list<string>
     * }|null
     */
    private function definition(string $catalogId): ?array
    {
        foreach (self::DEFINITIONS as $def) {
            if ($def['id'] === $catalogId) {
                return $def;
            }
        }

        return null;
    }

    private function allocateIdentifier(string $base, int $storagePid = 0): string
    {
        $candidate = $base;
        $suffix = 1;
        while ($this->repository->identifierExistsAtStoragePid($candidate, $storagePid)) {
            $candidate = $base . '-' . $suffix;
            ++$suffix;
        }

        return $candidate;
    }

    /**
     * @param list<string> $fallbackOptions
     *
     * @return array{defaultModel: string, modelOptions: list<string>}
     */
    private function resolveWizardModels(string $adapterType, string $fallbackDefault, array $fallbackOptions): array
    {
        $vendorKey = $this->vendorKey($adapterType);
        if ($vendorKey === '') {
            return [
                'defaultModel' => $fallbackDefault,
                'modelOptions' => $fallbackOptions,
            ];
        }

        $catalog = $this->catalogReader->read($vendorKey);
        $chatIds = [];
        foreach ($catalog as $model) {
            if (!$this->isWizardChatModel($model)) {
                continue;
            }
            $chatIds[] = $model->id;
        }

        if ($chatIds === []) {
            return [
                'defaultModel' => $fallbackDefault,
                'modelOptions' => $fallbackOptions,
            ];
        }

        $chatIds = $this->excludeStaleSnapshots($chatIds);
        $options = $this->pickWizardOptions($chatIds);
        if ($options === []) {
            return [
                'defaultModel' => $fallbackDefault,
                'modelOptions' => $fallbackOptions,
            ];
        }

        return [
            'defaultModel' => $this->pickDefaultModel($options),
            'modelOptions' => $options,
        ];
    }

    private function isWizardChatModel(ModelInfo $model): bool
    {
        if (!$this->isSelectableWizardModelId($model->id)) {
            return false;
        }

        $caps = $model->capabilities;
        if ($caps === []) {
            return true;
        }

        return in_array(Capability::CHAT, $caps, true)
            || in_array(Capability::COMPLETION, $caps, true);
    }

    private function isSelectableWizardModelId(string $id): bool
    {
        $lower = strtolower($id);

        return !str_contains($lower, 'embed')
            && !str_contains($lower, 'whisper')
            && !str_contains($lower, 'tts')
            && !str_contains($lower, 'image')
            && !str_contains($lower, 'audio')
            && !str_contains($lower, 'realtime')
            && !str_contains($lower, 'instruct')
            && !str_contains($lower, 'deep-research')
            && !str_contains($lower, 'codex')
            && !str_contains($lower, 'fable');
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function excludeStaleSnapshots(array $ids): array
    {
        $hasNewerGeneration = false;
        foreach ($ids as $id) {
            if (preg_match('/(?:sonnet-4|haiku-4|opus-4|gpt-4o|gpt-5|gemini-2\\.)/', $id) === 1) {
                $hasNewerGeneration = true;
                break;
            }
        }

        return array_values(array_filter(
            $ids,
            static function (string $id) use ($hasNewerGeneration): bool {
                if (str_ends_with($id, '-latest')) {
                    return false;
                }
                if ($hasNewerGeneration && preg_match('/-20240[23]\d{2}$/', $id) === 1) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function pickWizardOptions(array $ids): array
    {
        usort($ids, fn(string $a, string $b): int => $this->wizardModelScore($b) <=> $this->wizardModelScore($a));

        $picked = [];
        foreach (['haiku', 'mini', 'flash-lite', 'flash'] as $tier) {
            $match = $this->findBestTierMatch($ids, $tier, $picked);
            if ($match !== null) {
                $picked[] = $match;
            }
        }

        $sonnet = $this->findBestTierMatch($ids, 'sonnet', $picked);
        if ($sonnet !== null) {
            $picked[] = $sonnet;
        }

        foreach ($ids as $id) {
            if (count($picked) >= 3) {
                break;
            }
            if (!in_array($id, $picked, true)) {
                $picked[] = $id;
            }
        }

        return array_slice(array_values(array_unique($picked)), 0, 3);
    }

    /**
     * @param list<string> $ids
     * @param list<string> $picked
     */
    private function findBestTierMatch(array $ids, string $tier, array $picked): ?string
    {
        foreach ($ids as $id) {
            if (in_array($id, $picked, true)) {
                continue;
            }
            if (str_contains(strtolower($id), $tier)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param list<string> $options
     */
    private function pickDefaultModel(array $options): string
    {
        foreach ($options as $id) {
            $lower = strtolower($id);
            if (str_contains($lower, 'sonnet') && !str_contains($lower, 'haiku')) {
                return $id;
            }
        }

        foreach ($options as $id) {
            if (str_contains(strtolower($id), 'pro') || str_contains(strtolower($id), 'gpt-4o')) {
                return $id;
            }
        }

        return $options[0];
    }

    private function wizardModelScore(string $id): int
    {
        $score = 0;
        $lower = strtolower($id);

        if (preg_match('/(?:sonnet-4|haiku-4|opus-4|gpt-5|gemini-2\\.5|gemini-3)/', $lower) === 1) {
            $score += 100;
        }
        if (str_contains($lower, 'sonnet') && !str_contains($lower, 'haiku')) {
            $score += 50;
        }
        if (preg_match('/-2025\d{4}$/', $lower) === 1) {
            $score += 20;
        }
        if (preg_match('/-20240[23]\d{2}$/', $lower) === 1) {
            $score -= 40;
        }

        return $score;
    }

    private function vendorKey(string $adapterType): string
    {
        if (str_starts_with($adapterType, 'symfony.')) {
            return substr($adapterType, 8);
        }

        return '';
    }
}
