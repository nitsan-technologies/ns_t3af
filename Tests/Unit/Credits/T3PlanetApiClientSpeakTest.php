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

use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use NITSAN\NsT3AF\Credits\Http\T3PlanetHttpClient;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

final class T3PlanetApiClientSpeakTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousTypo3ConfVars = null;

    protected function setUp(): void
    {
        $this->previousTypo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key-' . str_repeat('x', 32);
    }

    protected function tearDown(): void
    {
        if ($this->previousTypo3ConfVars === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousTypo3ConfVars;
        }
    }

    public function testSpeakPostsToSpeakEndpointWithExpectedBody(): void
    {
        /** @var array<string, mixed>|null $capturedBody */
        $capturedBody = null;

        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('API/AI/Speak'),
                'POST',
                self::callback(static function (array $options) use (&$capturedBody): bool {
                    $capturedBody = $options['json'] ?? null;

                    return is_array($capturedBody);
                }),
            )
            ->willReturn(new Response(
                new Stream($this->memoryHandle((string) json_encode([
                    'status' => true,
                    'audio_base64' => base64_encode('fake-mp3'),
                    'mime_type' => 'audio/mpeg',
                    'model' => 'tts-1',
                ]))),
                200,
                ['Content-Type' => 'application/json'],
            ));

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new ExtensionConfiguration(),
        );

        $apiClient = new T3PlanetApiClient(new T3PlanetHttpClient($factory, $runtime));
        $payload = $apiClient->speak(
            'example.test',
            'uuid-tts-1',
            'text_to_speech',
            [
                'text' => 'Hello audio',
                'voice' => 'alloy',
                'format' => 'mp3',
                'speed' => 1.0,
                'extension_key' => 'ns_t3aa',
                'client_feature_key' => 'media.tts',
            ],
            'token-abc',
            'ns_t3aa',
        );

        self::assertIsArray($capturedBody);
        self::assertSame('example.test', $capturedBody['domain']);
        self::assertSame('uuid-tts-1', $capturedBody['request_uuid']);
        self::assertSame('text_to_speech', $capturedBody['feature_key']);
        self::assertSame('ns_t3aa', $capturedBody['extension_key']);
        self::assertSame('Hello audio', $capturedBody['meta_json']['text']);
        self::assertSame('alloy', $capturedBody['meta_json']['voice']);
        self::assertSame(base64_encode('fake-mp3'), $payload['audio_base64']);
    }

    public function testSpeakDefaultsFeatureKeyWhenEmpty(): void
    {
        /** @var array<string, mixed>|null $capturedBody */
        $capturedBody = null;

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')
            ->with(
                self::stringContains('API/AI/Speak'),
                'POST',
                self::callback(static function (array $options) use (&$capturedBody): bool {
                    $capturedBody = $options['json'] ?? null;

                    return is_array($capturedBody);
                }),
            )
            ->willReturn(new Response(
                new Stream($this->memoryHandle((string) json_encode(['status' => true, 'audio_base64' => '']))),
                200,
                ['Content-Type' => 'application/json'],
            ));

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $runtime = new RuntimeSettingsService($repository, new CredentialCipher(), new ExtensionConfiguration());

        $apiClient = new T3PlanetApiClient(new T3PlanetHttpClient($factory, $runtime));
        $apiClient->speak('example.test', 'uuid-tts-2', '', ['text' => 'Hi'], 'token-abc');

        self::assertIsArray($capturedBody);
        self::assertSame('text_to_speech', $capturedBody['feature_key']);
    }

    private function memoryHandle(string $contents): mixed
    {
        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, $contents);
        rewind($handle);

        return $handle;
    }
}
