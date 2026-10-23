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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Agent\Service\ProviderAgentToolIndexRebuildPolicy;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ProviderAgentToolIndexRebuildPolicyTest extends TestCase
{
    #[Test]
    public function skipsWhenEmbeddingSourceIsNone(): void
    {
        $policy = $this->policy(['agentEmbeddingSource' => EmbeddingSourceResolver::MODE_NONE]);

        self::assertFalse($policy->shouldRebuildAfterSave(null, $this->provider(), []));
    }

    #[Test]
    public function skipsChatOnlyTitleStyleSave(): void
    {
        $policy = $this->policy();
        $before = $this->provider(['model_id' => 'gpt-4o-mini']);
        $after = $this->provider(['model_id' => 'gpt-4o']);

        self::assertFalse($policy->shouldRebuildAfterSave($before, $after, ['api_key' => '']));
    }

    #[Test]
    public function triggersOnEmbeddingModelChange(): void
    {
        $policy = $this->policy();
        $before = $this->provider(['embedding_model_id' => 'text-embedding-3-small']);
        $after = $this->provider(['embedding_model_id' => 'text-embedding-3-large']);

        self::assertTrue($policy->shouldRebuildAfterSave($before, $after, ['api_key' => '']));
    }

    #[Test]
    public function triggersOnNewEmbeddingCapableProvider(): void
    {
        $policy = $this->policy();

        self::assertTrue($policy->shouldRebuildAfterSave(null, $this->provider(), []));
    }

    #[Test]
    public function triggersWhenApiKeySubmitted(): void
    {
        $policy = $this->policy();
        $provider = $this->provider();

        self::assertTrue($policy->shouldRebuildAfterSave($provider, $provider, ['api_key' => 'sk-test']));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function provider(array $overrides = []): Provider
    {
        return Provider::fromRow(array_merge([
            'uid' => 2,
            'identifier' => 'openai',
            'adapter_type' => 'symfony.openai',
            'capabilities' => 'chat,embeddings',
            'embedding_model_id' => 'text-embedding-3-small',
            'model_id' => 'gpt-4o',
        ], $overrides));
    }

    /**
     * @param array<string, string> $stored
     */
    private function policy(array $stored = []): ProviderAgentToolIndexRebuildPolicy
    {
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn(array_merge(
            ['agentEmbeddingSource' => EmbeddingSourceResolver::MODE_PROVIDER],
            $stored,
        ));

        return new ProviderAgentToolIndexRebuildPolicy(new AgentSettingsService($extensionSettings));
    }
}
