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

namespace NITSAN\NsT3AF\Tests\Unit\Service;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\Contract\VerifyResult;
use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Service\ProviderFormService;
use PHPUnit\Framework\TestCase;

final class ProviderFormServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('z', 32);
    }

    protected function tearDown(): void
    {
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testRequiresIdentifierTitleAndAdapterType(): void
    {
        $service = new ProviderFormService(
            $this->makeRepo(savedUid: 0),
            new AdapterRegistry(),
            new CredentialCipher(),
        );

        $result = $service->save(0, [], 1);

        self::assertFalse($result->ok);
        self::assertArrayHasKey('identifier', $result->errors);
        self::assertArrayHasKey('title', $result->errors);
        self::assertArrayHasKey('adapter_type', $result->errors);
    }

    public function testRejectsUnregisteredAdapterType(): void
    {
        $service = new ProviderFormService(
            $this->makeRepo(savedUid: 0),
            new AdapterRegistry(),
            new CredentialCipher(),
        );
        $result = $service->save(0, ['identifier' => 'a', 'title' => 'A', 'adapter_type' => 'symfony.unknown'], 1);

        self::assertFalse($result->ok);
        self::assertSame('Adapter type "symfony.unknown" is not registered.', $result->errors['adapter_type']);
    }

    public function testRejectsInvalidIdentifierPattern(): void
    {
        $service = new ProviderFormService(
            $this->makeRepo(savedUid: 0),
            new AdapterRegistry([$this->fakeAdapter()]),
            new CredentialCipher(),
        );
        $result = $service->save(0, ['identifier' => 'has space', 'title' => 'A', 'adapter_type' => 'symfony.openai'], 1);

        self::assertFalse($result->ok);
        self::assertArrayHasKey('identifier', $result->errors);
    }

    public function testRejectsDuplicateIdentifierForDifferentUid(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(new Provider(
            uid: 99,
            pid: 0,
            identifier: 'taken',
            title: 'X',
            adapterType: 'symfony.openai',
            endpointUrl: '',
            apiKeyCipher: '',
            modelId: '',
            embeddingModelId: '',
            capabilities: [],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
        ));

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(0, ['identifier' => 'taken', 'title' => 'A', 'adapter_type' => 'symfony.openai'], 1);

        self::assertFalse($result->ok);
        self::assertSame('Identifier "taken" is already in use.', $result->errors['identifier']);
    }

    public function testOpenAiCompatibleRequiresEndpointUrl(): void
    {
        $service = new ProviderFormService(
            $this->makeRepo(savedUid: 1),
            new AdapterRegistry([$this->fakeOpenAiCompatibleAdapter()]),
            new CredentialCipher(),
        );
        $result = $service->save(0, [
            'identifier' => 'custom-x',
            'title' => 'Custom host',
            'adapter_type' => Provider::ADAPTER_OPENAI_COMPATIBLE,
            'endpoint_url' => '',
        ], 1);

        self::assertFalse($result->ok);
        self::assertArrayHasKey('endpoint_url', $result->errors);
    }

    public function testOpenAiCompatibleAcceptsValidEndpointUrl(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $repo->expects(self::once())->method('save')->willReturn(9);

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeOpenAiCompatibleAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'custom-x',
            'title' => 'Custom host',
            'adapter_type' => Provider::ADAPTER_OPENAI_COMPATIBLE,
            'endpoint_url' => 'https://llm.example.com/v1',
            'api_key' => 'sk-plain-secret',
        ], 1);

        self::assertTrue($result->ok);
        self::assertSame(9, $result->uid);
    }

    public function testOpenAiCompatibleRejectsInvalidEndpointUrl(): void
    {
        $service = new ProviderFormService(
            $this->makeRepo(savedUid: 0),
            new AdapterRegistry([$this->fakeOpenAiCompatibleAdapter()]),
            new CredentialCipher(),
        );
        $result = $service->save(0, [
            'identifier' => 'custom-x',
            'title' => 'Custom host',
            'adapter_type' => Provider::ADAPTER_OPENAI_COMPATIBLE,
            'endpoint_url' => 'not-a-valid-url',
        ], 1);

        self::assertFalse($result->ok);
        self::assertArrayHasKey('endpoint_url', $result->errors);
    }

    public function testOllamaRequiresEndpointUrlWhenNoDefault(): void
    {
        $service = new ProviderFormService(
            $this->makeRepo(savedUid: 0),
            new AdapterRegistry([$this->fakeOllamaAdapter(defaultEndpoint: '')]),
            new CredentialCipher(),
        );
        $result = $service->save(0, [
            'identifier' => 'ollama-local',
            'title' => 'Ollama',
            'adapter_type' => Provider::ADAPTER_SYMFONY_OLLAMA,
            'endpoint_url' => '',
        ], 1);

        self::assertFalse($result->ok);
        self::assertArrayHasKey('endpoint_url', $result->errors);
        self::assertStringContainsString('Ollama', $result->errors['endpoint_url']);
    }

    public function testOllamaFillsDefaultEndpointWhenFieldEmpty(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return 13;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeOllamaAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'ollama-local',
            'title' => 'Ollama',
            'adapter_type' => Provider::ADAPTER_SYMFONY_OLLAMA,
            'endpoint_url' => '',
        ], 1);

        self::assertTrue($result->ok);
        self::assertSame('http://localhost:11434', $captured['endpoint_url'] ?? '');
    }

    public function testOllamaSavesWithEndpointAndNoApiKey(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return 12;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeOllamaAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'ollama-local',
            'title' => 'Ollama',
            'adapter_type' => Provider::ADAPTER_SYMFONY_OLLAMA,
            'endpoint_url' => 'http://host.docker.internal:11434',
            'api_key' => '',
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertSame('http://host.docker.internal:11434', $captured['endpoint_url']);
        self::assertSame('', $captured['api_key'] ?? '');
    }

    public function testLegacyOpenAiCompatibleAdapterTypeNormalizesOnSave(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return 11;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeOpenAiCompatibleAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'legacy-x',
            'title' => 'Legacy',
            'adapter_type' => 'symfony.openai_compatible',
            'endpoint_url' => 'https://example.com/v1',
            'api_key' => 'sk-plain-secret',
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertSame(Provider::ADAPTER_OPENAI_COMPATIBLE, $captured['adapter_type']);
    }

    public function testEncryptsPlaintextApiKeyOnInsert(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;
                return 7;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'openai-x',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => 'sk-plain-secret',
            'capabilities' => ['chat', 'streaming'],
            'is_default' => '1',
            'temperature' => '0.55',
            'priority' => '20',
        ], 1);

        self::assertTrue($result->ok);
        self::assertSame(7, $result->uid);
        self::assertNotNull($captured);
        self::assertStringStartsWith(CredentialCipher::PREFIX_V1, (string) $captured['api_key']);
        self::assertSame('chat,streaming', $captured['capabilities']);
        self::assertSame(1, $captured['is_default']);
        self::assertSame(0.55, $captured['temperature']);
        self::assertSame(20, $captured['priority']);
    }

    public function testSaveReturnsApiKeyErrorWhenSodiumUnavailableUnderDdevRepro(): void
    {
        putenv('T3AF_REPRO_NO_SODIUM=1');
        $_ENV['T3AF_REPRO_NO_SODIUM'] = '1';
        putenv('DDEV_PROJECT=phpunit');
        $_ENV['DDEV_PROJECT'] = 'phpunit';
        try {
            $repo = $this->createMock(ProviderRepositoryInterface::class);
            $repo->method('findByIdentifier')->willReturn(null);
            $repo->expects(self::never())->method('save');

            $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
            $result = $service->save(0, [
                'identifier' => 'openai-x',
                'title' => 'OpenAI',
                'adapter_type' => 'symfony.openai',
                'api_key' => 'sk-plain-secret',
            ], 1);

            self::assertFalse($result->ok);
            self::assertArrayHasKey('api_key', $result->errors);
            self::assertStringContainsString('ext-sodium', $result->errors['api_key']);
        } finally {
            putenv('T3AF_REPRO_NO_SODIUM');
            unset($_ENV['T3AF_REPRO_NO_SODIUM']);
            putenv('DDEV_PROJECT');
            unset($_ENV['DDEV_PROJECT']);
        }
    }

    public function testSaveReturnsApiKeyErrorWhenEncryptionKeyMissing(): void
    {
        $previous = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';
        try {
            $repo = $this->createMock(ProviderRepositoryInterface::class);
            $repo->method('findByIdentifier')->willReturn(null);
            $repo->expects(self::never())->method('save');

            $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
            $result = $service->save(0, [
                'identifier' => 'openai-x',
                'title' => 'OpenAI',
                'adapter_type' => 'symfony.openai',
                'api_key' => 'sk-plain-secret',
            ], 1);

            self::assertFalse($result->ok);
            self::assertArrayHasKey('api_key', $result->errors);
            self::assertStringContainsString('encryption key', $result->errors['api_key']);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
            } else {
                $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $previous;
            }
        }
    }

    public function testInsertSetsUnknownLastStatusDefaults(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return 9;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'openai-new',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => 'sk-plain-secret',
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertSame(Provider::LAST_STATUS_UNKNOWN, $captured['last_status']);
        self::assertSame(Provider::LAST_STATUS_UNKNOWN, $captured['last_status_message']);
        self::assertSame(0, $captured['last_status_at']);
    }

    public function testKeepsExistingApiKeyWhenInputBlankOnEdit(): void
    {
        $existing = Provider::fromRow([
            'uid' => 5,
            'identifier' => 'openai-x',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => (new CredentialCipher())->encrypt('sk-stored'),
        ]);

        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $repo->method('findByUid')->with(5)->willReturn($existing);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;
                return $uid;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(5, [
            'identifier' => 'openai-x',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => '',
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertArrayNotHasKey('api_key', $captured);
    }

    public function testEmbeddingModelIdAutoAddsEmbeddingsCapability(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return 8;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'mistral-x',
            'title' => 'Mistral',
            'adapter_type' => 'symfony.openai',
            'api_key' => 'sk-plain-secret',
            'model_id' => 'mistral-large-latest',
            'embedding_model_id' => 'mistral-embed',
            'capabilities' => ['chat', 'streaming'],
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertSame('mistral-embed', $captured['embedding_model_id']);
        self::assertStringContainsString(Capability::EMBEDDINGS, (string) $captured['capabilities']);
    }

    public function testEmptyEmbeddingModelIdDoesNotForceEmbeddingsCapability(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return 8;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(0, [
            'identifier' => 'mistral-x',
            'title' => 'Mistral',
            'adapter_type' => 'symfony.openai',
            'api_key' => 'sk-plain-secret',
            'model_id' => 'mistral-large-latest',
            'embedding_model_id' => '',
            'capabilities' => ['chat', 'streaming'],
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertSame('chat,streaming', $captured['capabilities']);
    }

    public function testCallsSetDefaultWhenIsDefaultChecked(): void
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $repo->method('save')->willReturn(42);
        $repo->expects(self::once())->method('setDefault')->with(42, 1);

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $service->save(0, [
            'identifier' => 'a',
            'title' => 'A',
            'adapter_type' => 'symfony.openai',
            'api_key' => 'sk-plain-secret',
            'is_default' => '1',
        ], 1);
    }

    public function testEditSavePersistsUncheckedIsEnabled(): void
    {
        $existing = Provider::fromRow([
            'uid' => 5,
            'identifier' => 'openai-x',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => (new CredentialCipher())->encrypt('sk-stored'),
            'is_enabled' => 1,
        ]);

        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $repo->method('findByUid')->with(5)->willReturn($existing);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return $uid;
            });

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(5, [
            'identifier' => 'openai-x',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => '',
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertSame(0, $captured['is_enabled']);
    }

    public function testEditSavePersistsUncheckedIsDefault(): void
    {
        $existing = Provider::fromRow([
            'uid' => 6,
            'identifier' => 'openai-default',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => (new CredentialCipher())->encrypt('sk-stored'),
            'is_default' => 1,
        ]);

        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $repo->method('findByUid')->with(6)->willReturn($existing);
        $captured = null;
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (int $uid, array $values) use (&$captured): int {
                $captured = $values;

                return $uid;
            });
        $repo->expects(self::never())->method('setDefault');

        $service = new ProviderFormService($repo, new AdapterRegistry([$this->fakeAdapter()]), new CredentialCipher());
        $result = $service->save(6, [
            'identifier' => 'openai-default',
            'title' => 'OpenAI',
            'adapter_type' => 'symfony.openai',
            'api_key' => '',
        ], 1);

        self::assertTrue($result->ok);
        self::assertNotNull($captured);
        self::assertSame(0, $captured['is_default']);
    }

    private function makeRepo(int $savedUid): ProviderRepositoryInterface
    {
        $repo = $this->createMock(ProviderRepositoryInterface::class);
        $repo->method('findByIdentifier')->willReturn(null);
        $repo->method('save')->willReturn($savedUid);
        return $repo;
    }

    private function fakeAdapter(): AdapterInterface
    {
        return new class implements AdapterInterface {
            public function getType(): string
            {
                return 'symfony.openai';
            }
            public function getDisplayName(): string
            {
                return 'OpenAI';
            }
            public function getDefaultEndpoint(): string
            {
                return '';
            }
            public function getDefaultCapabilities(): array
            {
                return [Capability::CHAT];
            }
            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::ok();
            }
            public function platform(Provider $provider): object
            {
                return new \stdClass();
            }
        };
    }

    private function fakeOllamaAdapter(string $defaultEndpoint = 'http://localhost:11434'): AdapterInterface
    {
        return new class ($defaultEndpoint) implements AdapterInterface {
            public function __construct(private readonly string $defaultEndpoint) {}

            public function getType(): string
            {
                return Provider::ADAPTER_SYMFONY_OLLAMA;
            }
            public function getDisplayName(): string
            {
                return 'Ollama (Symfony AI)';
            }
            public function getDefaultEndpoint(): string
            {
                return $this->defaultEndpoint;
            }
            public function getDefaultCapabilities(): array
            {
                return [Capability::CHAT, Capability::EMBEDDINGS];
            }
            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::ok();
            }
            public function platform(Provider $provider): object
            {
                return new \stdClass();
            }
        };
    }

    private function fakeOpenAiCompatibleAdapter(): AdapterInterface
    {
        return new class implements AdapterInterface {
            public function getType(): string
            {
                return Provider::ADAPTER_OPENAI_COMPATIBLE;
            }
            public function getDisplayName(): string
            {
                return 'Custom / Other';
            }
            public function getDefaultEndpoint(): string
            {
                return '';
            }
            public function getDefaultCapabilities(): array
            {
                return [Capability::CHAT];
            }
            public function testConnection(Provider $provider): VerifyResult
            {
                return VerifyResult::ok();
            }
            public function platform(Provider $provider): object
            {
                return new \stdClass();
            }
        };
    }
}
