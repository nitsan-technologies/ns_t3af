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

use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Service\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
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

    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = new CredentialCipher();
        $plain = 'sk-test-1234567890ABCDEF';

        $blob = $cipher->encrypt($plain);

        self::assertStringStartsWith(CredentialCipher::PREFIX_V1, $blob);
        self::assertNotSame($plain, $blob);
        self::assertSame($plain, $cipher->decrypt($blob));
    }

    public function testEncryptEmptyStringReturnsEmpty(): void
    {
        $cipher = new CredentialCipher();
        self::assertSame('', $cipher->encrypt(''));
        self::assertSame('', $cipher->decrypt(''));
    }

    public function testEncryptProducesUniqueCiphertext(): void
    {
        $cipher = new CredentialCipher();
        $a = $cipher->encrypt('same plaintext');
        $b = $cipher->encrypt('same plaintext');
        self::assertNotSame($a, $b, 'Random nonce must yield distinct ciphertexts');
    }

    public function testDecryptUnknownPrefixThrows(): void
    {
        $this->expectException(CipherException::class);
        (new CredentialCipher())->decrypt('plain-string-no-prefix');
    }

    public function testDecryptTamperedCiphertextThrows(): void
    {
        $cipher = new CredentialCipher();
        $blob = $cipher->encrypt('secret');
        $tampered = $blob . 'X';

        $this->expectException(CipherException::class);
        $cipher->decrypt($tampered);
    }

    public function testIsEncryptedDetectsPrefix(): void
    {
        $cipher = new CredentialCipher();
        self::assertTrue($cipher->isEncrypted(CredentialCipher::PREFIX_V1 . 'whatever'));
        self::assertFalse($cipher->isEncrypted('plain'));
        self::assertFalse($cipher->isEncrypted(''));
    }

    public function testMaskHidesMostOfKey(): void
    {
        $cipher = new CredentialCipher();
        self::assertSame('', $cipher->mask(''));
        self::assertSame('••', $cipher->mask('ab'));
        self::assertStringStartsWith('sk-', $cipher->mask('sk-very-long-secret'));
        self::assertStringContainsString('•', $cipher->mask('sk-very-long-secret'));
    }

    public function testMissingEncryptionKeyThrows(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';
        $this->expectException(CipherException::class);
        $this->expectExceptionMessage('encryption key');
        (new CredentialCipher())->encrypt('x');
    }

    public function testMissingSodiumThrowsCipherExceptionUnderDdevRepro(): void
    {
        putenv('T3AF_REPRO_NO_SODIUM=1');
        $_ENV['T3AF_REPRO_NO_SODIUM'] = '1';
        putenv('DDEV_PROJECT=phpunit');
        $_ENV['DDEV_PROJECT'] = 'phpunit';
        try {
            (new CredentialCipher())->encrypt('x');
            self::fail('Expected CipherException when Sodium repro is forced under DDEV');
        } catch (CipherException $e) {
            self::assertStringContainsString('ext-sodium', $e->getMessage());
        } finally {
            putenv('T3AF_REPRO_NO_SODIUM');
            unset($_ENV['T3AF_REPRO_NO_SODIUM']);
            putenv('DDEV_PROJECT');
            unset($_ENV['DDEV_PROJECT']);
        }
    }
}
