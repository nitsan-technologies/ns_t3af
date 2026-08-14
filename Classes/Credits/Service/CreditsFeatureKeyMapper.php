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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Contract\CreditsFeatureKeyAliasProviderInterface;
use NITSAN\NsT3AF\Credits\CreditsApiEndpoint;
use NITSAN\NsT3AF\Credits\CreditsFeatureKeyCatalog;
use NITSAN\NsT3AF\Credits\CreditsFeatureMapping;
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
        'content.generation' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'content.generation.default' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'content_generation' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'content_translation' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATE,
        'content.topic' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'content.outline' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'content.page' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'content.element' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'content.rewrite' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'translation.openai' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATE,
        'translation.simple' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATE,
        'translation.mistral' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATE,
        'translation.gemini' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATE,
        'translation.claude' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATE,
        'translation.xlf' => CreditsFeatureKeyCatalog::CONTENT_TRANSLATE,
        'seo.keywords' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'page.tree' => CreditsFeatureKeyCatalog::CONTENT_PAGE_STRUCTURE,
        'page_structure_generation' => CreditsFeatureKeyCatalog::CONTENT_PAGE_STRUCTURE,
        'easy_language' => CreditsFeatureKeyCatalog::EASY_LANGUAGE,
        'image_generation' => CreditsFeatureKeyCatalog::IMAGE_GENERATE,
        'media.dalle' => CreditsFeatureKeyCatalog::IMAGE_GENERATE,
        'media.dalle_variation' => CreditsFeatureKeyCatalog::IMAGE_GENERATE,
        'media.image' => CreditsFeatureKeyCatalog::IMAGE_GENERATE,
        'embedding' => CreditsFeatureKeyCatalog::EMBEDDING,
        'embed' => CreditsFeatureKeyCatalog::EMBEDDING,
        'rte.content' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'chat.response' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'chat.assistance' => CreditsFeatureKeyCatalog::ASSISTANT_CHAT,
        'prompt.improve' => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        'stream' => CreditsFeatureKeyCatalog::ASSISTANT_CHAT,
        'media.tts' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
        'tts' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
        'text_to_speech' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
        'voiceover' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
    ];

    /**
     * Legacy granular SEO/metadata keys → canonical feature + single meta_json field.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LEGACY_FIELD_ALIASES = [
        'seo.page_title' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'page_title'],
        'seo.title' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'page_title'],
        'seo_page_title' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'page_title'],
        'seo.meta_description' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'meta_description'],
        'seo_meta_description' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'meta_description'],
        'seo.meta' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'meta_description'],
        'seo.og_title' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'og_title'],
        'seo_og_title' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'og_title'],
        'seo.og_description' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'og_description'],
        'seo_og_description' => [CreditsFeatureKeyCatalog::SEO_PAGE_METADATA, 'og_description'],
        'metadata.alt_text' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'alt_text'],
        'metadata_alt_text' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'alt_text'],
        'metadata.title' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'title'],
        'metadata_title' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'title'],
        'metadata.description' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'description'],
        'metadata_description' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'description'],
        'file.alt_text' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'alt_text'],
        'file.alt_text.alttext_ai' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'alt_text'],
        'file.meta_title_description' => [CreditsFeatureKeyCatalog::SEO_IMAGE_METADATA, 'title'],
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
        return $this->mapWithMeta($clientFeatureKey, $options, $endpoint)->featureKey;
    }

    /**
     * Returns canonical feature_key plus optional meta_json additions (e.g. fields[] for legacy SEO keys).
     */
    public function mapWithMeta(string $clientFeatureKey, AiOptions $options, CreditsApiEndpoint $endpoint): CreditsFeatureMapping
    {
        $clientFeatureKey = trim($clientFeatureKey);
        if ($clientFeatureKey === '') {
            return new CreditsFeatureMapping($this->defaultForEndpoint($endpoint));
        }

        if ($endpoint === CreditsApiEndpoint::Stream) {
            return new CreditsFeatureMapping(CreditsFeatureKeyCatalog::ASSISTANT_CHAT);
        }

        if ($endpoint === CreditsApiEndpoint::Embed) {
            return new CreditsFeatureMapping(CreditsFeatureKeyCatalog::EMBEDDING);
        }

        if ($endpoint === CreditsApiEndpoint::Speak) {
            return new CreditsFeatureMapping(CreditsFeatureKeyCatalog::TEXT_TO_SPEECH);
        }

        if ($endpoint === CreditsApiEndpoint::Image) {
            return new CreditsFeatureMapping(CreditsFeatureKeyCatalog::IMAGE_GENERATE);
        }

        if (CreditsFeatureKeyCatalog::isCatalogKey($clientFeatureKey)) {
            return new CreditsFeatureMapping($clientFeatureKey);
        }

        $extensionKey = trim((string) ($options->extensionKey ?? ''));
        $legacyField = self::LEGACY_FIELD_ALIASES[$clientFeatureKey] ?? null;
        if ($legacyField !== null) {
            return $this->mappingWithField($legacyField[0], $legacyField[1]);
        }

        $mapped = $this->resolveAlias($clientFeatureKey, $extensionKey);
        if ($mapped !== null) {
            return new CreditsFeatureMapping($mapped);
        }

        $this->logger?->warning(
            'Unknown client feature_key for T3Planet Credits; falling back to content_generate. '
            . 'Register $GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTCONF\'][\'ns_t3af\'][\'creditsFeatureKeyAliases\'][your-extension].',
            [
                'client_feature_key' => $clientFeatureKey,
                'extension_key' => $extensionKey !== '' ? $extensionKey : 'unknown',
            ],
        );

        return new CreditsFeatureMapping(CreditsFeatureKeyCatalog::CONTENT_GENERATE);
    }

    private function defaultForEndpoint(CreditsApiEndpoint $endpoint): string
    {
        return match ($endpoint) {
            CreditsApiEndpoint::Embed => CreditsFeatureKeyCatalog::EMBEDDING,
            CreditsApiEndpoint::Stream => CreditsFeatureKeyCatalog::ASSISTANT_CHAT,
            CreditsApiEndpoint::Speak => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
            CreditsApiEndpoint::Image => CreditsFeatureKeyCatalog::IMAGE_GENERATE,
            CreditsApiEndpoint::Charge => CreditsFeatureKeyCatalog::CONTENT_GENERATE,
        };
    }

    private function mappingWithField(string $featureKey, string $field): CreditsFeatureMapping
    {
        return new CreditsFeatureMapping(
            featureKey: $featureKey,
            metaAdditions: ['fields' => [$field]],
            legacyField: $field,
        );
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
