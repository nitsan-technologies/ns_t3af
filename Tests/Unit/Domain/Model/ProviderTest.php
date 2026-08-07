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

namespace NITSAN\NsT3AF\Tests\Unit\Domain\Model;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Provider\Capability;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    public function testFromRowNormalizesLegacyOpenAiCompatibleAdapterType(): void
    {
        $p = Provider::fromRow(['adapter_type' => 'symfony.openai_compatible']);

        self::assertSame(Provider::ADAPTER_OPENAI_COMPATIBLE, $p->adapterType);
    }

    public function testNormalizeAdapterTypePassthrough(): void
    {
        self::assertSame('symfony.openai', Provider::normalizeAdapterType('symfony.openai'));
        self::assertSame('symfony.openai', Provider::normalizeAdapterType('symfony.open-ai'));
        self::assertSame(
            Provider::ADAPTER_OPENAI_COMPATIBLE,
            Provider::normalizeAdapterType('symfony.openai_compatible'),
        );
    }

    public function testFromRowHydratesAllFields(): void
    {
        $row = [
            'uid' => 12,
            'pid' => 0,
            'identifier' => 'openai-prod',
            'title' => 'OpenAI Production',
            'adapter_type' => 'symfony.openai',
            'endpoint_url' => 'https://api.openai.com/v1',
            'api_key' => 'enc:v1:abcd',
            'model_id' => 'gpt-4o',
            'embedding_model_id' => 'text-embedding-3-small',
            'capabilities' => 'chat,vision,streaming',
            'temperature' => '0.55',
            'system_prompt' => 'You are helpful.',
            'is_default' => 1,
            'priority' => 10,
            'last_used_at' => 1700000000,
            'last_status' => 'connected',
            'last_status_at' => 1700000100,
            'last_status_message' => 'OK',
            'be_groups' => '3,5',
        ];

        $p = Provider::fromRow($row);

        self::assertSame(12, $p->uid);
        self::assertSame('openai-prod', $p->identifier);
        self::assertSame('symfony.openai', $p->adapterType);
        self::assertSame('enc:v1:abcd', $p->apiKeyCipher);
        self::assertSame('gpt-4o', $p->modelId);
        self::assertSame('text-embedding-3-small', $p->embeddingModelId);
        self::assertSame(['chat', 'vision', 'streaming'], $p->capabilities);
        self::assertSame(0.55, $p->temperature);
        self::assertTrue($p->isDefault);
        self::assertSame(10, $p->priority);
        self::assertSame([3, 5], $p->beGroups);
    }

    public function testNormalizeLastStatusUsesUnknownWhenEmpty(): void
    {
        self::assertSame(Provider::LAST_STATUS_UNKNOWN, Provider::normalizeLastStatus(''));
        self::assertSame('connected', Provider::normalizeLastStatus('connected'));
    }

    public function testFromRowDefaultsForMissingKeys(): void
    {
        $p = Provider::fromRow([]);
        self::assertSame(0, $p->uid);
        self::assertSame('', $p->identifier);
        self::assertSame(Provider::LAST_STATUS_UNKNOWN, $p->lastStatus);
        self::assertSame('', $p->embeddingModelId);
        self::assertSame([], $p->capabilities);
        self::assertSame(0.7, $p->temperature);
        self::assertFalse($p->isDefault);
        self::assertSame(50, $p->priority);
    }

    public function testHasCapability(): void
    {
        $p = Provider::fromRow(['capabilities' => 'chat,embeddings']);
        self::assertTrue($p->hasCapability(Capability::CHAT));
        self::assertTrue($p->hasCapability(Capability::EMBEDDINGS));
        self::assertFalse($p->hasCapability(Capability::VISION));
    }

    public function testEffectiveEmbeddingModelFallsBackToChatModel(): void
    {
        $p = Provider::fromRow(['model_id' => 'gpt-4o', 'embedding_model_id' => '']);

        self::assertSame('gpt-4o', $p->effectiveEmbeddingModel());
    }

    public function testEffectiveEmbeddingModelUsesDedicatedField(): void
    {
        $p = Provider::fromRow(['model_id' => 'gpt-4o', 'embedding_model_id' => 'text-embedding-3-small']);

        self::assertSame('text-embedding-3-small', $p->effectiveEmbeddingModel());
    }

    public function testOllamaAdapterRules(): void
    {
        self::assertTrue(Provider::adapterRequiresEndpoint(Provider::ADAPTER_SYMFONY_OLLAMA));
        self::assertFalse(Provider::adapterRequiresApiKey(Provider::ADAPTER_SYMFONY_OLLAMA));
        self::assertTrue(Provider::adapterRequiresApiKey('symfony.openai'));
    }

    public function testFromRowDropsUnknownCapability(): void
    {
        $p = Provider::fromRow(['capabilities' => 'chat,bogus,vision']);
        self::assertSame(['chat', 'vision'], $p->capabilities);
    }
}
