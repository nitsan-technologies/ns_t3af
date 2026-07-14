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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Exception\CipherException;

/**
 * Symmetric encryption helper for sensitive credentials (API keys, tokens, …).
 *
 * Uses libsodium's `crypto_secretbox` with a 32-byte key derived from
 * `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`. Ciphertext is stored
 * with the `enc:v1:` prefix so future schemes can coexist via versioning.
 *
 * Plaintext NEVER leaves this service except as the direct return value of
 * {@see decrypt()}. Mask values for UI output via {@see mask()}.
 */
final class CredentialCipher
{
    /**
     * Versioned ciphertext prefix. Bump (`enc:v2:`, …) when introducing a new
     * algorithm or key derivation so existing rows remain decryptable.
     */
    public const PREFIX_V1 = 'enc:v1:';

    /**
     * Encrypt a plaintext secret.
     *
     * @param string $plain Plaintext value; passing `''` returns `''`.
     * @return string Versioned ciphertext (`enc:v1:` + base64 of `nonce|cipher`).
     * @throws CipherException If Sodium is unavailable or the system encryption key is missing.
     */
    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $this->assertRuntimeReady();
        $key = $this->key();
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        sodium_memzero($key);

        return self::PREFIX_V1 . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a value previously produced by {@see encrypt()}.
     *
     * @param string $blob Versioned ciphertext; passing `''` returns `''`.
     * @return string Recovered plaintext.
     * @throws CipherException If Sodium is unavailable, the prefix is unknown, the payload is
     *                         malformed, the encryption key is missing, or the ciphertext
     *                         was tampered with.
     */
    public function decrypt(#[\SensitiveParameter] string $blob): string
    {
        if ($blob === '') {
            return '';
        }
        $this->assertRuntimeReady();
        if (!str_starts_with($blob, self::PREFIX_V1)) {
            throw new CipherException('Unknown or missing ciphertext prefix.');
        }
        $raw = base64_decode(substr($blob, strlen(self::PREFIX_V1)), true);
        if ($raw === false || strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new CipherException('Malformed ciphertext payload.');
        }
        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key();
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            throw new CipherException('Decryption failed: ciphertext tampered or wrong encryption key.');
        }

        return $plain;
    }

    /**
     * Quick check whether a stored value uses the current ciphertext envelope.
     * Useful for migration code that may encounter pre-v1 plaintext rows.
     */
    public function isEncrypted(string $blob): bool
    {
        return str_starts_with($blob, self::PREFIX_V1);
    }

    /**
     * Display-only obfuscation for plaintext credentials.
     *
     * Keeps the first three characters (so OpenAI's `sk-` prefix or similar is
     * still recognisable) and replaces the rest with bullets. NEVER use the
     * return value as the input to {@see decrypt()}.
     */
    public function mask(#[\SensitiveParameter] string $plain): string
    {
        $len = strlen($plain);
        if ($len === 0) {
            return '';
        }
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return substr($plain, 0, 3) . str_repeat('•', max(8, $len - 3));
    }

    /**
     * @throws CipherException If Sodium or the TYPO3 encryption key is unavailable.
     */
    private function assertRuntimeReady(): void
    {
        $env = new EnvironmentRequirementService();
        if (!$env->hasSodium()) {
            throw new CipherException(
                'PHP extension "sodium" (ext-sodium) is required to store API keys securely. '
                . 'Enable it for the PHP used by the webserver (PHP-FPM/Apache), restart PHP, then retry. '
                . 'Check TYPO3 Environment → PHP Info for "sodium".',
            );
        }
        if (!$env->hasEncryptionKey()) {
            throw new CipherException(
                'TYPO3 system encryption key is missing. Set SYS/encryptionKey '
                . '(Install Tool → Configuration), then retry.',
            );
        }
    }

    /**
     * Derive the 32-byte secretbox key from the TYPO3 system encryption key.
     *
     * @throws CipherException If the system encryption key is missing or empty.
     */
    private function key(): string
    {
        $secret = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($secret === '') {
            throw new CipherException(
                'TYPO3 system encryption key is missing. Set SYS/encryptionKey '
                . '(Install Tool → Configuration), then retry.',
            );
        }

        return hash('sha256', $secret, true);
    }
}
