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
use NITSAN\NsT3AF\Credits\Service\RuntimeSettingsService;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class RuntimeSettingsServiceTokenTest extends TestCase
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

    public function testGetTokenPlainPrefersExtensionConfiguration(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')
            ->willReturnCallback(static function (string $extension, ?string $path = null): mixed {
                if ($path === 't3planetApiToken') {
                    return 'token-from-extension';
                }

                return [];
            });

        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'token_enc' => (new CredentialCipher())->encrypt('token-from-database'),
        ]);

        $service = new RuntimeSettingsService(
            $repository,
            new CredentialCipher(),
            $extensionConfiguration,
        );

        self::assertSame('token-from-extension', $service->getTokenPlain());
    }

    public function testGetTokenPlainFallsBackToDatabase(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')
            ->willReturnCallback(static function (string $extension, ?string $path = null): mixed {
                if ($path === 't3planetApiToken') {
                    return '';
                }

                return [];
            });

        $cipher = new CredentialCipher();
        $repository = $this->createMock(RuntimeSettingsRepository::class);
        $repository->method('findSingleton')->willReturn([
            'token_enc' => $cipher->encrypt('token-from-database'),
        ]);

        $service = new RuntimeSettingsService(
            $repository,
            $cipher,
            $extensionConfiguration,
        );

        self::assertSame('token-from-database', $service->getTokenPlain());
    }
}
