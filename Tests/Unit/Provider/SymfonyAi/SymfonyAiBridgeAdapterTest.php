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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\SymfonyAi;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\SymfonyAi\BridgeDescriptor;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiBridgeAdapter;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;

final class SymfonyAiBridgeAdapterTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-' . str_repeat('y', 32);
    }

    protected function tearDown(): void
    {
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testStaticAccessorsReflectDescriptor(): void
    {
        $adapter = $this->makeAdapter();
        self::assertSame('symfony.openai', $adapter->getType());
        self::assertSame('Openai (Symfony AI)', $adapter->getDisplayName());
        self::assertSame('https://api.openai.com/v1', $adapter->getDefaultEndpoint());
        self::assertContains(Capability::CHAT, $adapter->getDefaultCapabilities());
    }

    public function testGetTypeCanonicalizesHyphenatedSymfonyVendorSlug(): void
    {
        $adapter = new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-open-ai-platform',
                vendorKey: 'open-ai',
                type: 'symfony.open-ai',
                displayName: 'Open-ai (Symfony AI)',
                defaultEndpoint: 'https://api.openai.com/v1',
                defaultCapabilities: [Capability::CHAT],
            ),
            new CredentialCipher(),
            $this->createMock(RequestFactory::class),
        );

        self::assertSame('symfony.openai', $adapter->getType());
    }

    public function testTestConnectionFailsWhenApiKeyMissing(): void
    {
        $adapter = $this->makeAdapter();
        // makeProvider() leaves apiKeyCipher empty — bearer-auth probe must reject.
        $result = $adapter->testConnection($this->makeProvider());

        self::assertFalse($result->ok);
        self::assertNotNull($result->message);
        self::assertStringContainsString('API key', (string) $result->message);
    }

    public function testTestConnectionUsesDefaultEndpointWhenProviderEndpointMissing(): void
    {
        $adapter = $this->makeAdapter();
        $provider = $this->makeProviderWith(endpoint: '', apiKey: '');
        $result = $adapter->testConnection($provider);

        self::assertFalse($result->ok);
        self::assertStringContainsString('API key', (string) $result->message);
    }

    public function testTestConnectionFailsWhenNoEndpointAndNoDefaultEndpoint(): void
    {
        $adapter = new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-openai-platform',
                vendorKey: 'openai',
                type: 'symfony.openai',
                displayName: 'Openai (Symfony AI)',
                defaultEndpoint: '',
                defaultCapabilities: [Capability::CHAT],
            ),
            new CredentialCipher(),
            $this->createMock(RequestFactory::class),
        );

        $provider = $this->makeProviderWith(endpoint: '', apiKey: '');
        $result = $adapter->testConnection($provider);

        self::assertFalse($result->ok);
        self::assertStringContainsString('Endpoint URL is required', (string) $result->message);
    }

    public function testPlatformThrowsWhenRuntimeMissing(): void
    {
        $adapter = $this->makeAdapter();
        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionMessageMatches('/Symfony AI Platform runtime/');
        $adapter->platform($this->makeProvider());
    }

    public function testOllamaTestConnectionSucceedsWithoutApiKey(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('http://ollama.test:11434/api/tags'),
                self::identicalTo('GET'),
                self::arrayHasKey('headers'),
            )
            ->willReturn(new Response('php://temp', 200, ['Content-Type' => 'application/json']));

        $adapter = new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-ollama-platform',
                vendorKey: 'ollama',
                type: 'symfony.ollama',
                displayName: 'Ollama (Symfony AI)',
                defaultEndpoint: 'http://localhost:11434',
                defaultCapabilities: [Capability::CHAT, Capability::STREAMING, Capability::EMBEDDINGS],
            ),
            new CredentialCipher(),
            $requestFactory,
        );

        $provider = $this->makeProviderWith(
            endpoint: 'http://ollama.test:11434',
            apiKey: '',
            adapterType: 'symfony.ollama',
        );
        $result = $adapter->testConnection($provider);

        self::assertTrue($result->ok);
    }

    public function testOllamaPlatformUsesProviderEndpointWhenRuntimePresent(): void
    {
        if (!class_exists(\Symfony\AI\Platform\Bridge\Ollama\Factory::class)) {
            self::markTestSkipped('symfony/ai-ollama-platform is not installed.');
        }

        $adapter = new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-ollama-platform',
                vendorKey: 'ollama',
                type: 'symfony.ollama',
                displayName: 'Ollama (Symfony AI)',
                defaultEndpoint: 'http://localhost:11434',
                defaultCapabilities: [Capability::CHAT, Capability::EMBEDDINGS],
            ),
            new CredentialCipher(),
            $this->createMock(RequestFactory::class),
        );

        $provider = $this->makeProviderWith(
            endpoint: 'http://ollama.test:11434',
            apiKey: '',
            adapterType: 'symfony.ollama',
        );

        $platform = $adapter->platform($provider);

        self::assertTrue(method_exists($platform, 'invoke'));
    }

    public function testOllamaPlatformThrowsWhenEndpointMissing(): void
    {
        if (!class_exists(\Symfony\AI\Platform\Bridge\Ollama\Factory::class)) {
            self::markTestSkipped('symfony/ai-ollama-platform is not installed.');
        }

        $adapter = new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-ollama-platform',
                vendorKey: 'ollama',
                type: 'symfony.ollama',
                displayName: 'Ollama (Symfony AI)',
                defaultEndpoint: '',
                defaultCapabilities: [Capability::CHAT],
            ),
            new CredentialCipher(),
            $this->createMock(RequestFactory::class),
        );

        $provider = $this->makeProviderWith(endpoint: '', apiKey: '', adapterType: 'symfony.ollama');

        $this->expectException(AdapterRuntimeException::class);
        $this->expectExceptionMessage('Endpoint URL is required');

        $adapter->platform($provider);
    }

    public function testHyphenatedTypeUsesCanonicalProbeConfigKey(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/models'),
                self::identicalTo('GET'),
                self::arrayHasKey('headers'),
            )
            ->willReturn(new Response('php://temp', 200));

        $adapter = new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-open-ai-platform',
                vendorKey: 'open-ai',
                type: 'symfony.open-ai',
                displayName: 'Open AI (Symfony AI)',
                defaultEndpoint: 'https://api.openai.com',
                defaultCapabilities: [Capability::CHAT, Capability::STREAMING],
            ),
            new CredentialCipher(),
            $requestFactory,
        );

        $cipher = new CredentialCipher();
        $provider = $this->makeProviderWith(endpoint: 'https://api.openai.com', apiKey: $cipher->encrypt('sk-test'));
        $result = $adapter->testConnection($provider);

        self::assertTrue($result->ok);
    }

    public function testHuggingFaceTestConnectionUsesFeatureExtractionPipeline(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/pipeline/feature-extraction'),
                self::identicalTo('POST'),
                self::callback(static function (array $options): bool {
                    return ($options['json']['inputs'] ?? null) === 'connection test';
                }),
            )
            ->willReturn(new JsonResponse([-0.1, 0.2, 0.3], 200));

        $adapter = new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-hugging-face-platform',
                vendorKey: 'huggingface',
                type: 'symfony.huggingface',
                displayName: 'Huggingface (Symfony AI)',
                defaultEndpoint: '',
                defaultCapabilities: [Capability::CHAT, Capability::EMBEDDINGS],
            ),
            new CredentialCipher(),
            $requestFactory,
        );

        $cipher = new CredentialCipher();
        $provider = $this->makeProviderWith(
            endpoint: '',
            apiKey: $cipher->encrypt('hf_test_token'),
            adapterType: 'symfony.huggingface',
        );
        $provider = new Provider(
            uid: $provider->uid,
            pid: $provider->pid,
            identifier: $provider->identifier,
            title: $provider->title,
            adapterType: $provider->adapterType,
            endpointUrl: $provider->endpointUrl,
            apiKeyCipher: $provider->apiKeyCipher,
            modelId: $provider->modelId,
            embeddingModelId: 'sentence-transformers/all-MiniLM-L6-v2',
            capabilities: [Capability::EMBEDDINGS],
            temperature: $provider->temperature,
            systemPrompt: $provider->systemPrompt,
            isDefault: $provider->isDefault,
            priority: $provider->priority,
            lastUsedAt: $provider->lastUsedAt,
            lastStatus: $provider->lastStatus,
            lastStatusAt: $provider->lastStatusAt,
            lastStatusMessage: $provider->lastStatusMessage,
        );

        $result = $adapter->testConnection($provider);

        self::assertTrue($result->ok);
        self::assertStringContainsString('Embedding probe OK', (string) $result->message);
    }

    private function makeAdapter(): SymfonyAiBridgeAdapter
    {
        return new SymfonyAiBridgeAdapter(
            new BridgeDescriptor(
                packageName: 'symfony/ai-openai-platform',
                vendorKey: 'openai',
                type: 'symfony.openai',
                displayName: 'Openai (Symfony AI)',
                defaultEndpoint: 'https://api.openai.com/v1',
                defaultCapabilities: [Capability::CHAT, Capability::STREAMING],
            ),
            new CredentialCipher(),
            $this->createMock(\TYPO3\CMS\Core\Http\RequestFactory::class),
        );
    }

    private function makeProvider(): Provider
    {
        return $this->makeProviderWith(endpoint: 'https://api.openai.com/v1', apiKey: '');
    }

    private function makeProviderWith(string $endpoint, string $apiKey, string $adapterType = 'symfony.openai'): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'openai-test',
            title: 'Test',
            adapterType: $adapterType,
            endpointUrl: $endpoint,
            apiKeyCipher: $apiKey,
            modelId: 'gpt-4o',
            embeddingModelId: '',
            capabilities: [Capability::CHAT],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: true,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
        );
    }
}
