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

final class T3PlanetApiClientStreamTest extends TestCase
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

    public function testStreamPassesExtensionKeyInBody(): void
    {
        /** @var array<string, mixed>|null $capturedBody */
        $capturedBody = null;

        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('API/AI/Stream'),
                'POST',
                self::callback(static function (array $options) use (&$capturedBody): bool {
                    $capturedBody = $options['json'] ?? null;

                    return is_array($capturedBody);
                }),
            )
            ->willReturn(new Response(
                new Stream($this->memoryHandle("event: usage\ndata: {}\n\n")),
                200,
                ['Content-Type' => 'text/event-stream'],
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
        $lines = $apiClient->stream(
            'example.test',
            'uuid-1',
            'seo_title',
            ['prompt' => 'Hello'],
            'token-abc',
            new AiOptions(extensionKey: 'ns_t3ai'),
        );
        iterator_to_array($lines, false);

        self::assertIsArray($capturedBody);
        self::assertSame('example.test', $capturedBody['domain']);
        self::assertSame('uuid-1', $capturedBody['request_uuid']);
        self::assertSame('seo_title', $capturedBody['feature_key']);
        self::assertSame('ns_t3ai', $capturedBody['extension_key']);
        self::assertSame('Hello', $capturedBody['meta_json']['prompt']);
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
