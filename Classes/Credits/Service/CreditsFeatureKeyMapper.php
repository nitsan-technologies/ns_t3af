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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Contract\CreditsFeatureKeyAliasProviderInterface;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\CreditsFeatureKeyCatalog;
use Psr\Log\LoggerInterface;

/**
 * Maps extension-local feature keys to composer {@see ns_ai_feature_cost.feature_key} values.
 *
 * Register extra aliases per extension via
 * `$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases'][extension-key]`.
 *
 * @api
 */
final class CreditsFeatureKeyMapper
{
    /**
     * Aliases shared by any extension (dot notation, legacy telemetry keys, …).
     *
     * @var array<string, string>
     */
    private const GLOBAL_ALIASES = [
        'content.generation' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'content.generation.default' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'content_generation' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'content_translation' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
        'content.topic' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'content.outline' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'content.page' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'content.element' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'content.rewrite' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'translation.openai' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
        'translation.simple' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
        'translation.mistral' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
        'translation.gemini' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
        'translation.claude' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
        'translation.xlf' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATION,
        'seo.page_title' => CreditsFeatureKeyCatalog::SEO_PAGE_TITLE,
        'seo.title' => CreditsFeatureKeyCatalog::SEO_PAGE_TITLE,
        'seo.meta_description' => CreditsFeatureKeyCatalog::SEO_META_DESCRIPTION,
        'seo.og_title' => CreditsFeatureKeyCatalog::SEO_OG_TITLE,
        'seo.og_description' => CreditsFeatureKeyCatalog::SEO_OG_DESCRIPTION,
        'seo.keywords' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'seo.meta' => CreditsFeatureKeyCatalog::SEO_META_DESCRIPTION,
        'page.tree' => CreditsFeatureKeyCatalog::PAGE_STRUCTURE_GENERATION,
        'page_structure_generation' => CreditsFeatureKeyCatalog::PAGE_STRUCTURE_GENERATION,
        'easy_language' => CreditsFeatureKeyCatalog::EASY_LANGUAGE,
        'image_generation' => CreditsFeatureKeyCatalog::IMAGE_GENERATION,
        'media.dalle' => CreditsFeatureKeyCatalog::IMAGE_GENERATION,
        'media.dalle_variation' => CreditsFeatureKeyCatalog::IMAGE_GENERATION,
        'media.image' => CreditsFeatureKeyCatalog::IMAGE_GENERATION,
        'embedding' => CreditsFeatureKeyCatalog::EMBEDDING,
        'embed' => CreditsFeatureKeyCatalog::EMBEDDING,
        'metadata.alt_text' => CreditsFeatureKeyCatalog::METADATA_ALT_TEXT,
        'metadata.title' => CreditsFeatureKeyCatalog::METADATA_TITLE,
        'metadata.description' => CreditsFeatureKeyCatalog::METADATA_DESCRIPTION,
        'metadata_alt_text' => CreditsFeatureKeyCatalog::METADATA_ALT_TEXT,
        'metadata_title' => CreditsFeatureKeyCatalog::METADATA_TITLE,
        'metadata_description' => CreditsFeatureKeyCatalog::METADATA_DESCRIPTION,
        'rte.content' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'chat.response' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'chat.assistance' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'prompt.improve' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        'stream' => CreditsFeatureKeyCatalog::STREAM,
        'media.tts' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
        'tts' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
        'text_to_speech' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
    ];

    /**
     * @param iterable<CreditsFeatureKeyAliasProviderInterface> $aliasProviders
     */
    public function __construct(
        private readonly iterable $aliasProviders = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Returns a composer-catalog feature_key for Charge / Stream / Embed.
     */
    public function map(string $clientFeatureKey, AiOptions $options, CreditsApiEndpoint $endpoint): string
    {
        $clientFeatureKey = trim($clientFeatureKey);
        if ($clientFeatureKey === '') {
            return $this->defaultForEndpoint($endpoint);
        }

        if ($endpoint === CreditsApiEndpoint::Stream) {
            return CreditsFeatureKeyCatalog::STREAM;
        }

        if ($endpoint === CreditsApiEndpoint::Embed) {
            return CreditsFeatureKeyCatalog::EMBEDDING;
        }

        if ($endpoint === CreditsApiEndpoint::Speak) {
            return CreditsFeatureKeyCatalog::TEXT_TO_SPEECH;
        }

        if (CreditsFeatureKeyCatalog::isCatalogKey($clientFeatureKey)) {
            return $clientFeatureKey;
        }

        $extensionKey = trim((string) ($options->extensionKey ?? ''));
        $mapped = $this->resolveAlias($clientFeatureKey, $extensionKey);
        if ($mapped !== null) {
            return $mapped;
        }

        $this->logger?->warning(
            'Unknown client feature_key for T3Planet Credits; falling back to content_generation. '
            . 'Register $GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTCONF\'][\'ns_t3af\'][\'creditsFeatureKeyAliases\'][your-extension].',
            [
                'client_feature_key' => $clientFeatureKey,
                'extension_key' => $extensionKey !== '' ? $extensionKey : 'unknown',
            ],
        );

        return CreditsFeatureKeyCatalog::CONTENT_GENERATION;
    }

    private function defaultForEndpoint(CreditsApiEndpoint $endpoint): string
    {
        return match ($endpoint) {
            CreditsApiEndpoint::Embed => CreditsFeatureKeyCatalog::EMBEDDING,
            CreditsApiEndpoint::Stream => CreditsFeatureKeyCatalog::STREAM,
            CreditsApiEndpoint::Speak => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
            CreditsApiEndpoint::Charge => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
        };
    }

    private function resolveAlias(string $clientFeatureKey, string $extensionKey): ?string
    {
        if ($extensionKey !== '') {
            $extensionAliases = $this->extensionAliases($extensionKey);
            if (isset($extensionAliases[$clientFeatureKey])) {
                return $extensionAliases[$clientFeatureKey];
            }
        }

        return self::GLOBAL_ALIASES[$clientFeatureKey] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function extensionAliases(string $extensionKey): array
    {
        $aliases = $this->registeredExtensionAliases($extensionKey);
        foreach ($this->aliasProviders as $provider) {
            if (!$provider->isAvailable() || $provider->getExtensionKey() !== $extensionKey) {
                continue;
            }
            $aliases = array_merge($aliases, $provider->getAliases());
        }

        return $aliases;
    }

    /**
     * @return array<string, string>
     */
    private function registeredExtensionAliases(string $extensionKey): array
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ns_t3af']['creditsFeatureKeyAliases'][$extensionKey] ?? [];
        if (!is_array($configured)) {
            return [];
        }

        $normalized = [];
        foreach ($configured as $clientKey => $catalogKey) {
            if (!is_string($clientKey) || !is_string($catalogKey)) {
                continue;
            }
            $clientKey = trim($clientKey);
            $catalogKey = trim($catalogKey);
            if ($clientKey === '' || $catalogKey === '') {
                continue;
            }
            $normalized[$clientKey] = $catalogKey;
        }

        return $normalized;
    }
}
