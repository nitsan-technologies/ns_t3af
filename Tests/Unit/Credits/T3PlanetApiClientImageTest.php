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

final class T3PlanetApiClientImageTest extends TestCase
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

    public function testGenerateImagePostsToImageEndpointWithExpectedBody(): void
    {
        /** @var array<string, mixed>|null $capturedBody */
        $capturedBody = null;

        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('API/AI/Image'),
                'POST',
                self::callback(static function (array $options) use (&$capturedBody): bool {
                    $capturedBody = $options['json'] ?? null;

                    return is_array($capturedBody);
                }),
            )
            ->willReturn(new Response(
                new Stream($this->memoryHandle((string) json_encode([
                    'status' => true,
                    'images' => [['b64_json' => base64_encode('png')]],
                    'model' => 'gpt-image-1',
                ]))),
                200,
                ['Content-Type' => 'application/json'],
            ));

        $apiClient = new T3PlanetApiClient(new T3PlanetHttpClient($factory, $this->runtimeSettings()));
        $payload = $apiClient->generateImage(
            'example.test',
            'uuid-image-1',
            'image_generation',
            [
                'prompt' => 'A mountain',
                'size' => '1024x1024',
                'count' => 1,
                'extension_key' => 'ns_t3ai',
                'client_feature_key' => 'media.dalle',
            ],
            'token-abc',
            'ns_t3ai',
        );

        self::assertIsArray($capturedBody);
        self::assertSame('example.test', $capturedBody['domain']);
        self::assertSame('uuid-image-1', $capturedBody['request_uuid']);
        self::assertSame('image_generation', $capturedBody['feature_key']);
        self::assertSame('ns_t3ai', $capturedBody['extension_key']);
        self::assertSame('A mountain', $capturedBody['meta_json']['prompt']);
        self::assertSame('gpt-image-1', $payload['model']);
    }

    private function runtimeSettings(): RuntimeSettingsService
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            't3planet_api_base_url' => 'https://composer.example',
        ]);

        return new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new ExtensionConfiguration(),
        );
    }

    private function memoryHandle(string $body): mixed
    {
        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, $body);
        rewind($handle);

        return $handle;
    }
}
