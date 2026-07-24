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

namespace NITSAN\NsT3AF\Tests\Unit\Credits;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Service\CreditsMetaJsonBuilder;
use PHPUnit\Framework\TestCase;

final class CreditsMetaJsonBuilderTest extends TestCase
{
    public function testBuildIncludesAttributionAndPrompt(): void
    {
        $meta = CreditsMetaJsonBuilder::build(
            'Hello',
            new AiOptions(
                extensionKey: 'ns_news_comments',
                featureKey: 'comment_moderation',
                featureLabel: 'Moderate comment',
                requestSource: 'backend_module',
                temperature: 0.7,
                contentEntityType: 'tx_news_domain_model_news',
                contentEntityUid: 42,
                extra: ['custom_flag' => true],
            ),
        );

        self::assertSame('Hello', $meta['prompt']);
        self::assertSame('ns_news_comments', $meta['extension_key']);
        self::assertSame('Moderate comment', $meta['feature_label']);
        self::assertSame('backend_module', $meta['request_source']);
        self::assertSame('tx_news_domain_model_news', $meta['content_entity_type']);
        self::assertSame(42, $meta['content_entity_uid']);
        self::assertSame(0.7, $meta['temperature']);
        self::assertTrue($meta['custom_flag']);
    }

    public function testWithAttributionOverridesExtensionKeyFromExtra(): void
    {
        $meta = CreditsMetaJsonBuilder::withAttribution(
            ['extension_key' => 'ns_t3ai', 'prompt' => 'x'],
            new AiOptions(extensionKey: 'ns_news_comments'),
        );

        self::assertSame('ns_news_comments', $meta['extension_key']);
    }

    public function testCallerExtensionKeyEmptyWhenUnset(): void
    {
        self::assertSame('', CreditsMetaJsonBuilder::callerExtensionKey(new AiOptions()));
    }

    public function testBuildOmitsCreditsProviderIdentifierFromMetaJson(): void
    {
        $meta = CreditsMetaJsonBuilder::build(
            'Translate this',
            new AiOptions(
                providerIdentifier: CreditsProviderIdentifier::IDENTIFIER,
                extensionKey: 'ns_t3ai',
                extra: ['provider_identifier' => CreditsProviderIdentifier::IDENTIFIER],
            ),
        );

        self::assertSame('Translate this', $meta['prompt']);
        self::assertArrayNotHasKey('provider_identifier', $meta);
    }

    public function testBuildKeepsExplicitUpstreamProviderIdentifier(): void
    {
        $meta = CreditsMetaJsonBuilder::build(
            'Hello',
            new AiOptions(providerIdentifier: 'openai'),
        );

        self::assertSame('openai', $meta['provider_identifier']);
    }

    public function testBuildResolvesPromptFromMessagesWhenPromptArgumentEmpty(): void
    {
        $meta = CreditsMetaJsonBuilder::build(
            '',
            new AiOptions(
                extra: [
                    'messages' => [
                        ['role' => 'user', 'content' => 'Translate to German: Hello'],
                    ],
                ],
            ),
        );

        self::assertSame('Translate to German: Hello', $meta['prompt']);
    }

    public function testBuildForEmbedOmitsChatParameters(): void
    {
        $meta = CreditsMetaJsonBuilder::build(
            'Vectorize this',
            new AiOptions(
                temperature: 0.7,
                maxTokens: 512,
                systemPrompt: 'You are helpful',
            ),
            ['Vectorize this'],
        );

        self::assertSame('Vectorize this', $meta['prompt']);
        self::assertSame(['Vectorize this'], $meta['inputs']);
        self::assertArrayNotHasKey('temperature', $meta);
        self::assertArrayNotHasKey('max_tokens', $meta);
        self::assertArrayNotHasKey('system_prompt', $meta);
    }
}
