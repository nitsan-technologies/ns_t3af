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

namespace NITSAN\NsT3AF\Tests\Unit\Settings;

use NITSAN\NsT3AF\Service\CredentialCipher;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSecretRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsSecretService;
use NITSAN\NsT3AF\Tests\Unit\Support\ProviderTestStubs;
use PHPUnit\Framework\TestCase;

final class ExtensionSettingsSecretServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 32);
    }

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $service = $this->createService();

        $encrypted = $service->encryptValues('ns_t3ai', [
            'stabilityAiApiKey' => 'sk-test-secret',
            'imageSize' => '1024x1024',
        ]);

        self::assertStringStartsWith('enc:v1:', $encrypted['stabilityAiApiKey']);
        self::assertSame('1024x1024', $encrypted['imageSize']);

        $decrypted = $service->decryptValues('ns_t3ai', $encrypted);
        self::assertSame('sk-test-secret', $decrypted['stabilityAiApiKey']);
    }

    public function testMergeForSavePreservesSecretWhenSubmittedEmpty(): void
    {
        $service = $this->createService();
        $cipher = new CredentialCipher();

        $existingRaw = [
            'stabilityAiApiKey' => $cipher->encrypt('stored-secret'),
        ];

        $stored = $service->mergeForSave('ns_t3ai', $existingRaw, [
            'stabilityAiApiKey' => '',
            'imageGenerateMode' => 'core',
        ]);

        self::assertSame('core', $stored['imageGenerateMode']);
        self::assertSame('stored-secret', $service->decryptValues('ns_t3ai', $stored)['stabilityAiApiKey']);
    }

    public function testMaskValuesForDisplayClearsSecretValues(): void
    {
        $service = $this->createService();

        $masked = $service->maskValuesForDisplay('ns_t3ai', [
            'stabilityAiApiKey' => 'plain-secret',
            'imageSize' => '1024x1024',
        ]);

        self::assertSame('', $masked['stabilityAiApiKey']);
        self::assertSame('1024x1024', $masked['imageSize']);
    }

    public function testMergeForSaveIgnoresMaskedPlaceholderWithPrefix(): void
    {
        $service = $this->createService();
        $cipher = new CredentialCipher();

        $existingRaw = [
            'stabilityAiApiKey' => $cipher->encrypt('sk-vabcdefghijklmnopqrstuvwxyz12345'),
        ];

        $stored = $service->mergeForSave('ns_t3ai', $existingRaw, [
            'stabilityAiApiKey' => $cipher->mask('sk-vabcdefghijklmnopqrstuvwxyz12345'),
        ]);

        self::assertSame(
            'sk-vabcdefghijklmnopqrstuvwxyz12345',
            $service->decryptValues('ns_t3ai', $stored)['stabilityAiApiKey'],
        );
    }

    private function createService(): ExtensionSettingsSecretService
    {
        return new ExtensionSettingsSecretService(
            new ExtensionSettingsSecretRegistry([ProviderTestStubs::t3AiSecretProvider()]),
            new CredentialCipher(),
        );
    }
}
