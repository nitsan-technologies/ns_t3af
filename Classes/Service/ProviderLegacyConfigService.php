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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use NITSAN\NsT3AF\Utility\ProviderSlugMapper;

/**
 * Builds a legacy-shaped extension configuration array from provider DB rows
 * plus non-provider ext_conf keys (translation, MCP, auth, credits, …).
 *
 * Used by ns_t3cs and other legacy-shaped config consumers during the provider migration.
 */
final class ProviderLegacyConfigService
{
    /**
     * ext_conf keys that are not backed by provider rows.
     *
     * @var list<string>
     */
    private const NON_PROVIDER_KEYS = [
        'deepl_api_key',
        'deepl_api_url',
        'google_api_key',
        'google_api_url',
        'defaultModelForTranslation',
        'basicAuthEnabled',
        'basicAuthUsername',
        'basicAuthPassword',
        'enableApiQuotaEmailNotification',
        'apiQuotaNotificationEmail',
        't3planetApiBaseUrl',
        't3planetCreditsDomain',
        't3planetApiToken',
        'mcpBasePath',
        'enableMcpServer',
        'requireAuth',
        'rateLimitGlobal',
        'logAllToolCalls',
        'allowAnonymousReadOnly',
        'accessTokenLifetime',
        'refreshTokenLifetime',
        'codeLifetime',
        'mcpRemoteTokenLifetime',
        'sessionLifetime',
        'rateLimitEnabled',
        'rateLimitAuthorize',
        'rateLimitAuthorizeWindow',
        'rateLimitAuthorizeGet',
        'rateLimitAuthorizeGetWindow',
        'rateLimitToken',
        'rateLimitTokenWindow',
        'rateLimitRegister',
        'rateLimitRegisterWindow',
        'rateLimitRevoke',
        'rateLimitRevokeWindow',
        'oauthDefaultClientId',
        'oauthDefaultRedirectUris',
        'oauthDefaultScopes',
        'oauthMaxActiveTokensPerUser',
        'openai_admin_api_key',
    ];

    /**
     * ext_conf keys owned by ns_t3af but inherited by child extensions when empty.
     *
     * @var list<string>
     */
    private const UNIVERSE_ONLY_NON_PROVIDER_KEYS = [
        'openai_admin_api_key',
    ];

    /**
     * @var array<string, array{api_key: string, model: string, embedding_model?: string, temperature: string, max_tokens: string, endpoint?: string, endpoint_model?: string, enable_flag?: string}>
     */
    private const SLUG_FIELD_MAP = [
        'openai' => [
            'api_key' => 'openai_api_key',
            'model' => 'openai_model',
            'embedding_model' => 'openai_embedding_model',
            'temperature' => 'openai_temperature',
            'max_tokens' => 'openai_max_tokens',
        ],
        'claude' => [
            'api_key' => 'anthropic_api_key',
            'model' => 'anthropic_model',
            'embedding_model' => 'anthropic_embedding_model',
            'temperature' => 'anthropic_temperature',
            'max_tokens' => 'anthropic_max_tokens',
        ],
        'gemini' => [
            'api_key' => 'gemini_api_key',
            'model' => 'gemini_model',
            'embedding_model' => 'gemini_embedding_model',
            'temperature' => 'gemini_temperature',
            'max_tokens' => 'gemini_max_tokens',
        ],
        'mistral' => [
            'api_key' => 'mistral_api_key',
            'model' => 'mistral_model',
            'embedding_model' => 'mistral_embedding_model',
            'temperature' => 'mistral_temperature',
            'max_tokens' => 'mistral_max_tokens',
        ],
        'deepseek' => [
            'api_key' => 'deepseek_api_key',
            'model' => 'deepseek_model',
            'temperature' => 'deepseek_temperature',
            'max_tokens' => 'deepseek_max_tokens',
        ],
        'xai' => [
            'api_key' => 'xai_api_key',
            'model' => 'xai_model',
            'temperature' => 'xai_temperature',
            'max_tokens' => 'xai_max_tokens',
        ],
        'azure' => [
            'api_key' => 'azure_api_key',
            'model' => 'azure_api_model',
            'endpoint' => 'azure_api_endpoint',
            'endpoint_model' => 'azure_api_version',
            'temperature' => 'openai_temperature',
            'max_tokens' => 'openai_max_tokens',
        ],
        'customllm' => [
            'api_key' => 'custom_llm_api_key',
            'model' => 'custom_llm_model_name',
            'endpoint' => 'custom_llm_api_url',
            'temperature' => 'custom_llm_temperature',
            'max_tokens' => 'openai_max_tokens',
            'enable_flag' => 'enable_custom_llm_model',
        ],
        'ollama' => [
            'api_key' => 'ollama_api_key',
            'model' => 'ollama_model',
            'endpoint' => 'ollama_api_url',
            'temperature' => 'ollama_temperature',
            'max_tokens' => 'ollama_max_tokens',
        ],
    ];

    public function __construct(
        private readonly ProviderRepositoryInterface $providers,
        private readonly CredentialCipher $cipher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildLegacyConfig(string $extensionKey = 'ns_t3af'): array
    {
        $rawExtConf = AiUniverseUtilityHelper::getExtensionConf($extensionKey);
        $config = [];

        foreach (self::NON_PROVIDER_KEYS as $key) {
            if (array_key_exists($key, $rawExtConf)) {
                $config[$key] = $rawExtConf[$key];
            }
        }

        $enabledProviders = array_filter(
            $this->providers->findAll(),
            static fn(Provider $provider): bool => $provider->isEnabled,
        );

        foreach ($enabledProviders as $provider) {
            $this->applyProviderToConfig($config, $provider);
        }

        $defaultProvider = $this->providers->findDefault();
        if ($defaultProvider !== null && $defaultProvider->isEnabled) {
            $config['defaultModel'] = ProviderSlugMapper::slugFromProvider($defaultProvider);
            $config['defaultOpenAIModel'] = $defaultProvider->modelId !== ''
                ? $defaultProvider->modelId
                : ($config['openai_model'] ?? 'gpt-4.1');
        } elseif (!isset($config['defaultModel'])) {
            $config['defaultModel'] = 'openai';
        }

        $embeddingProvider = $this->resolveEmbeddingProvider(array_values($enabledProviders), $defaultProvider);
        if ($embeddingProvider !== null) {
            $config['defaultEmbeddingsModel'] = ProviderSlugMapper::slugFromProvider($embeddingProvider);
        } elseif (!isset($config['defaultEmbeddingsModel'])) {
            $config['defaultEmbeddingsModel'] = 'openai';
        }

        return $this->inheritUniverseOnlyKeys($config, $extensionKey);
    }

    /**
     * Keys stored on ns_t3af ext_conf but required by child extensions (statistics, alerts).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function inheritUniverseOnlyKeys(array $config, string $extensionKey): array
    {
        if ($extensionKey === 'ns_t3af') {
            return $config;
        }

        $universeConf = AiUniverseUtilityHelper::getExtensionConf('ns_t3af');
        foreach (self::UNIVERSE_ONLY_NON_PROVIDER_KEYS as $key) {
            if (isset($config[$key]) && trim((string) $config[$key]) !== '') {
                continue;
            }
            if (isset($universeConf[$key]) && trim((string) $universeConf[$key]) !== '') {
                $config[$key] = $universeConf[$key];
            }
        }

        return $config;
    }

    public function resolveDefaultSlug(): string
    {
        $default = $this->providers->findDefault();
        if ($default !== null && $default->isEnabled) {
            return ProviderSlugMapper::slugFromProvider($default);
        }

        return 'unknown';
    }

    /**
     * @param list<Provider> $providers
     */
    private function resolveEmbeddingProvider(array $providers, ?Provider $defaultProvider): ?Provider
    {
        if ($defaultProvider !== null
            && $defaultProvider->isEnabled
            && in_array(Capability::EMBEDDINGS, $defaultProvider->capabilities, true)
        ) {
            return $defaultProvider;
        }

        foreach ($providers as $provider) {
            if (in_array(Capability::EMBEDDINGS, $provider->capabilities, true)) {
                return $provider;
            }
        }

        return $defaultProvider;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function applyProviderToConfig(array &$config, Provider $provider): void
    {
        $slug = ProviderSlugMapper::slugFromProvider($provider);
        $map = self::SLUG_FIELD_MAP[$slug] ?? null;
        if ($map === null) {
            return;
        }

        $apiKey = $this->decryptApiKey($provider);
        if ($apiKey !== '') {
            $config[$map['api_key']] = $apiKey;
        }

        if ($provider->modelId !== '') {
            $config[$map['model']] = $provider->modelId;
        }

        if (isset($map['embedding_model']) && $provider->embeddingModelId !== '') {
            $config[$map['embedding_model']] = $provider->embeddingModelId;
        }

        $config[$map['temperature']] = $provider->temperature;
        if (!isset($config[$map['max_tokens']])) {
            $config[$map['max_tokens']] = 1024;
        }

        if (isset($map['endpoint']) && $provider->endpointUrl !== '') {
            $config[$map['endpoint']] = $provider->endpointUrl;
        }

        if (isset($map['enable_flag'])) {
            $config[$map['enable_flag']] = 1;
        }
    }

    private function decryptApiKey(Provider $provider): string
    {
        if ($provider->apiKeyCipher === '') {
            return '';
        }

        try {
            return $this->cipher->decrypt($provider->apiKeyCipher);
        } catch (\Throwable) {
            return '';
        }
    }
}
