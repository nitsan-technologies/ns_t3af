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

final class T3PlanetApiClientCatalogLanguageTest extends TestCase
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

    public function testFeaturesSendsLanguage(): void
    {
        /** @var array<string, mixed>|null $capturedBody */
        $capturedBody = null;
        $client = $this->clientCapturing('API/AI/Features', $capturedBody, ['status' => true, 'language' => 'de', 'features' => []]);

        $client->features('example.ddev.site', 'tok', 'de');

        self::assertIsArray($capturedBody);
        self::assertSame('example.ddev.site', $capturedBody['domain'] ?? null);
        self::assertSame('de', $capturedBody['language'] ?? null);
    }

    public function testProductsSendsLanguageNextToRedirect(): void
    {
        /** @var array<string, mixed>|null $capturedBody */
        $capturedBody = null;
        $client = $this->clientCapturing('API/AI/Products', $capturedBody, ['status' => true, 'language' => 'de', 'products' => []]);

        $client->products('example.ddev.site', 'tok', 'https://example.ddev.site/typo3/', 'de');

        self::assertIsArray($capturedBody);
        self::assertSame('de', $capturedBody['language'] ?? null);
        self::assertSame('https://example.ddev.site/typo3/', $capturedBody['redirect_to'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $capturedBody
     * @param array<string, mixed> $responseJson
     */
    private function clientCapturing(string $pathContains, ?array &$capturedBody, array $responseJson): T3PlanetApiClient
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::stringContains($pathContains),
                'POST',
                self::callback(static function (array $options) use (&$capturedBody): bool {
                    $capturedBody = $options['json'] ?? null;

                    return is_array($capturedBody);
                }),
            )
            ->willReturn(new Response(
                new Stream($this->memoryHandle(json_encode($responseJson, JSON_THROW_ON_ERROR))),
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

        $http = new T3PlanetHttpClient($factory, $runtime);

        return new T3PlanetApiClient($http);
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
