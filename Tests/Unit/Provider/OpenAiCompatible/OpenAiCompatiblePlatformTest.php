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

namespace NITSAN\NsT3AF\Tests\Unit\Provider\OpenAiCompatible;

use GuzzleHttp\Psr7\Response;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Provider\Capability;
use NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatibleAdapter;
use NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatiblePlatform;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiCompatiblePlatformTest extends TestCase
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

    public function testStreamUsesV1ChatCompletionsForOllamaPortWithoutV1Suffix(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::equalTo('http://host.docker.internal:11434/v1/chat/completions'),
                self::identicalTo('POST'),
                self::callback(static function (array $options): bool {
                    self::assertArrayNotHasKey('Authorization', $options['headers'] ?? []);

                    return ($options['json']['stream'] ?? false) === true;
                }),
            )
            ->willReturn(new Response(200, [], "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n"));

        $adapter = new OpenAiCompatibleAdapter(new CredentialCipher(), $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'http://host.docker.internal:11434',
            apiKeyCipher: '',
        );

        $chunks = iterator_to_array($this->platform($adapter, $provider)->stream('llama3', 'hello'), false);

        self::assertSame(['hi'], $chunks);
    }

    public function testStreamUsesStandardPathWhenBaseAlreadyEndsWithV1(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::equalTo('https://api.example.com/v1/chat/completions'),
                self::identicalTo('POST'),
                self::anything(),
            )
            ->willReturn(new Response(200, [], "data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\n"));

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'https://api.example.com/v1',
            apiKeyCipher: $cipher->encrypt('secret'),
        );

        iterator_to_array($this->platform($adapter, $provider)->stream('gpt-4o', 'hello'), false);
    }

    public function testStreamUsesV1PrefixForSymfonyOllamaAdapterEvenWithout11434Port(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::equalTo('http://ollama.internal/v1/chat/completions'),
                self::identicalTo('POST'),
                self::anything(),
            )
            ->willReturn(new Response(200, [], "data: {\"choices\":[{\"delta\":{\"content\":\"x\"}}]}\n\n"));

        $adapter = new OpenAiCompatibleAdapter(new CredentialCipher(), $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_SYMFONY_OLLAMA,
            endpointUrl: 'http://ollama.internal',
            apiKeyCipher: '',
        );

        iterator_to_array($this->platform($adapter, $provider)->stream('llama3', 'hello'), false);
    }

    public function testSpeechBuildsCorrectPostBodyAndHeaders(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->willReturnCallback(static function (string $url, string $method, array $options) use (&$capturedUrl, &$capturedOptions): Response {
                $capturedUrl = $url;
                $capturedOptions = $options;

                return new Response(200, [], 'binary-audio');
            });

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: $cipher->encrypt('sk-test'),
        );

        $ttsOptions = new \NITSAN\NsT3AF\Api\TtsOptions(voice: 'nova', format: 'opus', speed: 1.25);
        $audio = $this->platform($adapter, $provider)->speech('tts-1', 'Hello world', $ttsOptions);

        self::assertSame('binary-audio', $audio);
        self::assertStringContainsString('audio/speech', (string) $capturedUrl);
        self::assertSame('POST', 'POST');
        self::assertIsArray($capturedOptions);
        $json = $capturedOptions['json'];
        self::assertSame('tts-1', $json['model']);
        self::assertSame('Hello world', $json['input']);
        self::assertSame('nova', $json['voice']);
        self::assertSame('opus', $json['response_format']);
        self::assertSame(1.25, $json['speed']);
        self::assertSame('application/octet-stream', $capturedOptions['headers']['Accept']);
        self::assertStringStartsWith('Bearer ', $capturedOptions['headers']['Authorization']);
    }

    public function testSpeechThrowsOnNon2xxResponse(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')
            ->willReturn(new Response(400, [], json_encode(['error' => ['message' => 'invalid voice']]) ?: ''));

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: $cipher->encrypt('sk-test'),
        );

        $this->expectException(\NITSAN\NsT3AF\Exception\AdapterRuntimeException::class);
        $this->expectExceptionMessage('invalid voice');
        $this->platform($adapter, $provider)->speech('tts-1', 'Hello', new \NITSAN\NsT3AF\Api\TtsOptions());
    }

    public function testGenerateImagesUsesSymfonyOpenAiDefaultEndpointWhenProviderEndpointIsEmpty(): void
    {
        $capturedUrl = null;
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->willReturnCallback(static function (string $url, string $method, array $options) use (&$capturedUrl): Response {
                $capturedUrl = $url;

                return new Response(200, [], json_encode([
                    'data' => [['url' => 'https://example.com/image.png']],
                ]) ?: '');
            });

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider('symfony.openai', '', $cipher->encrypt('sk-test'));

        $images = $this->platform($adapter, $provider)->generateImages(
            'gpt-image-1',
            'A red balloon',
            new \NITSAN\NsT3AF\Api\ImageGenerationOptions(size: '1024x1024', count: 1),
        );

        self::assertSame('https://example.com/image.png', $images[0]['url'] ?? '');
        self::assertSame('https://api.openai.com/v1/images/generations', $capturedUrl);
    }

    public function testGenerateImagesBuildsCorrectPostBody(): void
    {
        $capturedOptions = null;
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->willReturnCallback(static function (string $url, string $method, array $options) use (&$capturedOptions): Response {
                $capturedOptions = $options;

                return new Response(200, [], json_encode([
                    'data' => [['url' => 'https://example.com/image.png']],
                ]) ?: '');
            });

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: $cipher->encrypt('sk-test'),
        );

        $images = $this->platform($adapter, $provider)->generateImages(
            'dall-e-3',
            'A red balloon',
            new \NITSAN\NsT3AF\Api\ImageGenerationOptions(size: '1024x1024', count: 1),
        );

        self::assertSame('https://example.com/image.png', $images[0]['url'] ?? '');
        self::assertIsArray($capturedOptions);
        self::assertSame('dall-e-3', $capturedOptions['json']['model']);
        self::assertSame('A red balloon', $capturedOptions['json']['prompt']);
    }

    public function testInvokePreservesStringMessageContent(): void
    {
        $capturedJson = null;
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/chat/completions'),
                self::identicalTo('POST'),
                self::callback(static function (array $options) use (&$capturedJson): bool {
                    $capturedJson = $options['json'] ?? null;

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'alt text result']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]) ?: ''));

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: $cipher->encrypt('sk-test'),
        );

        $result = $this->platform($adapter, $provider)->invoke('gpt-4o', [
            'messages' => [['role' => 'user', 'content' => 'describe this image']],
        ]);

        self::assertSame('alt text result', $result['content']);
        self::assertIsArray($capturedJson);
        self::assertSame('describe this image', $capturedJson['messages'][0]['content']);
    }

    public function testInvokePassesArrayContentThroughUnchanged(): void
    {
        $capturedJson = null;
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('/chat/completions'),
                self::identicalTo('POST'),
                self::callback(static function (array $options) use (&$capturedJson): bool {
                    $capturedJson = $options['json'] ?? null;

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'a dog in a park']]],
                'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 6],
            ]) ?: ''));

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: $cipher->encrypt('sk-test'),
        );

        $visionContent = [
            ['type' => 'text', 'text' => 'What is in this image?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/dog.jpg']],
        ];

        $result = $this->platform($adapter, $provider)->invoke('gpt-4o', [
            'messages' => [['role' => 'user', 'content' => $visionContent]],
        ]);

        self::assertSame('a dog in a park', $result['content']);
        self::assertIsArray($capturedJson);
        // Array content must be passed as-is — not cast to the string "Array"
        self::assertSame($visionContent, $capturedJson['messages'][0]['content']);
    }

    public function testInvokeSkipsMessageRowsWithEmptyArrayContent(): void
    {
        $capturedJson = null;
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->willReturnCallback(static function (string $url, string $method, array $options) use (&$capturedJson): Response {
                $capturedJson = $options['json'] ?? null;

                return new Response(200, [], json_encode([
                    'choices' => [['message' => ['content' => 'ok']]],
                ]) ?: '');
            });

        $cipher = new CredentialCipher();
        $adapter = new OpenAiCompatibleAdapter($cipher, $factory);
        $provider = $this->makeProvider(
            adapterType: Provider::ADAPTER_OPENAI_COMPATIBLE,
            endpointUrl: 'https://api.openai.com/v1',
            apiKeyCipher: $cipher->encrypt('sk-test'),
        );

        $this->platform($adapter, $provider)->invoke('gpt-4o', [
            'messages' => [['role' => 'user', 'content' => []]],
        ]);

        self::assertIsArray($capturedJson);
        // Empty-array content row is skipped; messages list must not contain it
        $payloadMessages = $capturedJson['messages'];
        foreach ($payloadMessages as $msg) {
            self::assertNotSame([], $msg['content'] ?? 'not-array');
        }
    }

    private function platform(OpenAiCompatibleAdapter $adapter, Provider $provider): OpenAiCompatiblePlatform
    {
        $platform = $adapter->platform($provider);
        if (!$platform instanceof OpenAiCompatiblePlatform) {
            self::fail('Expected OpenAiCompatiblePlatform from adapter.');
        }

        return $platform;
    }

    private function makeProvider(string $adapterType, string $endpointUrl, string $apiKeyCipher): Provider
    {
        return new Provider(
            uid: 1,
            pid: 0,
            identifier: 'p',
            title: 'P',
            adapterType: $adapterType,
            endpointUrl: $endpointUrl,
            apiKeyCipher: $apiKeyCipher,
            modelId: 'm',
            embeddingModelId: '',
            capabilities: [Capability::CHAT, Capability::STREAMING],
            temperature: 0.7,
            systemPrompt: '',
            isDefault: false,
            priority: 50,
            lastUsedAt: 0,
            lastStatus: '',
            lastStatusAt: 0,
            lastStatusMessage: '',
            beGroups: [],
        );
    }
}
