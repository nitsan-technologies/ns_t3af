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

use NITSAN\NsT3AF\Credits\CreditsApiErrorCodes;
use NITSAN\NsT3AF\Credits\Domain\Repository\RuntimeSettingsRepository;
use NITSAN\NsT3AF\Credits\Exception\CreditsApiException;
use NITSAN\NsT3AF\Credits\Http\T3PlanetHttpClient;
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

final class T3PlanetHttpClientTest extends TestCase
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

    public function testDecodeResponseMapsEmpty500BodyToInternalError(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->willReturn(new Response(new Stream(fopen('php://memory', 'r+')), 500, []));

        $client = $this->createClient($factory);

        try {
            $client->postJson('Charge', ['domain' => 'example.test']);
            self::fail('Expected CreditsApiException');
        } catch (CreditsApiException $exception) {
            self::assertSame(CreditsApiErrorCodes::INTERNAL_ERROR, $exception->errorCode);
            self::assertSame(500, $exception->httpStatus);
            self::assertStringContainsString('empty body', $exception->getMessage());
        }
    }

    public function testDecodeResponseMapsRateLimitedPayload(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains('API/AI/Token'),
                'POST',
                self::callback(static function (array $options): bool {
                    return ($options['http_errors'] ?? null) === false;
                }),
            )
            ->willReturn($this->jsonResponse([
                'status' => false,
                'error_code' => 'rate_limited',
                'retry_after' => 60,
            ], 429));

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new ExtensionConfiguration(),
        );

        $client = new T3PlanetHttpClient($factory, $runtime);

        try {
            $client->postJson('Token', ['license_keys' => 'KEY']);
            self::fail('Expected CreditsApiException');
        } catch (CreditsApiException $exception) {
            self::assertSame('rate_limited', $exception->errorCode);
            self::assertSame(429, $exception->httpStatus);
            self::assertSame(60, $exception->extra['retry_after']);
        }
    }

    private function createClient(RequestFactory $factory): T3PlanetHttpClient
    {
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            't3planet_api_base_url' => 'https://composer.example',
        ]);
        $runtime = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            new ExtensionConfiguration(),
        );

        return new T3PlanetHttpClient($factory, $runtime);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $statusCode): Response
    {
        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, json_encode($payload, JSON_THROW_ON_ERROR));
        rewind($handle);

        return new Response(new Stream($handle), $statusCode, ['Content-Type' => 'application/json']);
    }
}
