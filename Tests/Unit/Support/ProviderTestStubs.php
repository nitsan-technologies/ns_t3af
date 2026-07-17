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

namespace NITSAN\NsT3AF\Tests\Unit\Support;

use NITSAN\NsT3AF\Contract\BrandContextFeatureScopeProviderInterface;
use NITSAN\NsT3AF\Contract\BrandContextPromptCategoryProviderInterface;
use NITSAN\NsT3AF\Contract\CreditsFeatureKeyAliasProviderInterface;
use NITSAN\NsT3AF\Contract\EmbeddingCapabilityProviderInterface;
use NITSAN\NsT3AF\Contract\ExtensionSettingsSecretProviderInterface;
use NITSAN\NsT3AF\Contract\ExtensionSettingsStorageProbeProviderInterface;
use NITSAN\NsT3AF\Credits\CreditsFeatureKeyCatalog;
use NITSAN\NsT3AF\Feature\T3AfAiLogChannelProvider;
use NITSAN\NsT3AF\Registry\ExtensionSettingsStorageProbeRegistry;
use NITSAN\NsT3AF\Registry\McpToolsExtensionCardProviderRegistry;
use NITSAN\NsT3AF\Registry\WizardSuiteBadgeProviderRegistry;
use NITSAN\NsT3AF\Service\AiLogChannelCatalog;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Reusable provider stubs for ns_t3af unit tests (foundation-only; no child package deps).
 */
final class ProviderTestStubs
{
    /**
     * @return list<BrandContextFeatureScopeProviderInterface>
     */
    public static function t3AiBrandContextScopeProviders(): array
    {
        return [new T3AiBrandContextScopeProviderStub()];
    }

    public static function t3AiSecretProvider(): ExtensionSettingsSecretProviderInterface
    {
        return new T3AiSecretProviderStub();
    }

    /**
     * @return list<EmbeddingCapabilityProviderInterface>
     */
    public static function embeddingCapabilityProviders(): array
    {
        return [
            new EmbeddingCapabilityStub('ns_t3cs'),
            new EmbeddingCapabilityStub('ns_t3as'),
            new EmbeddingCapabilityStub('ns_t3ac'),
        ];
    }

    public static function t3AiStorageProbeRegistry(): ExtensionSettingsStorageProbeRegistry
    {
        return new ExtensionSettingsStorageProbeRegistry([
            new T3AiStorageProbeProviderStub(),
        ]);
    }

    /**
     * @return list<BrandContextPromptCategoryProviderInterface>
     */
    public static function t3AiPromptCategoryProviders(): array
    {
        return [new T3AiPromptCategoryProviderStub()];
    }

    public static function emptySuiteBadgeRegistry(): WizardSuiteBadgeProviderRegistry
    {
        return new WizardSuiteBadgeProviderRegistry([]);
    }

    public static function emptyMcpToolsCardRegistry(): McpToolsExtensionCardProviderRegistry
    {
        return new McpToolsExtensionCardProviderRegistry([]);
    }

    /**
     * @return list<\NITSAN\NsT3AF\Contract\AiLogChannelProviderInterface>
     */
    public static function foundationAiLogChannelProviders(): array
    {
        return [new T3AfAiLogChannelProvider()];
    }

    public static function aiLogChannelCatalog(): AiLogChannelCatalog
    {
        return new AiLogChannelCatalog(self::foundationAiLogChannelProviders());
    }

    /**
     * @return list<CreditsFeatureKeyAliasProviderInterface>
     */
    public static function creditsAliasProviders(): array
    {
        return [
            new T3AiCreditsAliasProviderStub(),
            new T3AaCreditsAliasProviderStub(),
        ];
    }
}

final class T3AiBrandContextScopeProviderStub implements BrandContextFeatureScopeProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3ai';
    }

    public function supportsScope(string $scope): bool
    {
        return in_array($scope, ['seo', 'page', 'content'], true);
    }

    public function getSupportedScopes(): array
    {
        return ['seo', 'page', 'content'];
    }

    public function getScopeLabelKeys(): array
    {
        return [
            'seo' => 'module.aiContext.usedByFeature.seo',
            'page' => 'module.aiContext.usedByFeature.page',
            'content' => 'module.aiContext.usedByFeature.content',
        ];
    }
}

final class T3AiSecretProviderStub implements ExtensionSettingsSecretProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3ai';
    }

    public function getSecretFieldNames(): array
    {
        return ['stabilityAiApiKey'];
    }
}

final class EmbeddingCapabilityStub implements EmbeddingCapabilityProviderInterface
{
    public function __construct(
        private readonly string $extensionKey,
    ) {}

    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded($this->extensionKey);
    }
}

final class T3AiStorageProbeProviderStub implements ExtensionSettingsStorageProbeProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3ai';
    }

    public function getProbeKeys(): array
    {
        return ['stabilityAiApiKey'];
    }
}

final class T3AiPromptCategoryProviderStub implements BrandContextPromptCategoryProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3ai';
    }

    public function getCategoryBrandScopes(): array
    {
        return [
            'seo' => 'seo',
            'pages' => 'page',
            'content' => 'content',
        ];
    }
}

final class T3AiCreditsAliasProviderStub implements CreditsFeatureKeyAliasProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3ai';
    }

    public function getAliases(): array
    {
        return [
            'news.title' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
            'tca.field_suggestion' => CreditsFeatureKeyCatalog::CONTENT_GENERATION,
            'media.tts' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
        ];
    }
}

final class T3AaCreditsAliasProviderStub implements CreditsFeatureKeyAliasProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3aa';
    }

    public function getAliases(): array
    {
        return [
            'file.alt_text' => CreditsFeatureKeyCatalog::METADATA_ALT_TEXT,
            'file.alt_text.alttext_ai' => CreditsFeatureKeyCatalog::METADATA_ALT_TEXT,
            'file.meta_title_description' => CreditsFeatureKeyCatalog::METADATA_TITLE,
            'media.tts' => CreditsFeatureKeyCatalog::TEXT_TO_SPEECH,
        ];
    }
}
