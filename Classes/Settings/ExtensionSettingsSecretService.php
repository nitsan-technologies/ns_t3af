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

namespace NITSAN\NsT3AF\Settings;

use NITSAN\NsT3AF\Exception\CipherException;
use NITSAN\NsT3AF\Service\CredentialCipher;

/**
 * Encrypts, decrypts, and masks secret extension settings values.
 *
 * @internal
 */
final class ExtensionSettingsSecretService
{
    public function __construct(
        private readonly ExtensionSettingsSecretRegistry $registry,
        private readonly CredentialCipher $cipher,
    ) {}

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public function decryptValues(string $extensionKey, array $values): array
    {
        foreach ($this->registry->secretFields($extensionKey) as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $values[$field] = $this->decryptValue((string) $values[$field]);
        }

        return $values;
    }

    /**
     * @param array<string, string> $existingRaw
     * @param array<string, string> $submitted
     * @return array<string, string>
     */
    public function mergeForSave(string $extensionKey, array $existingRaw, array $submitted): array
    {
        $merged = $this->decryptValues($extensionKey, $existingRaw);

        foreach ($submitted as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $normalized = is_scalar($value) ? trim((string) $value) : '';

            if ($this->registry->isSecret($extensionKey, $key)) {
                if ($normalized === '' || $this->isMaskPlaceholder($normalized)) {
                    continue;
                }
                $merged[$key] = $normalized;
                continue;
            }

            $merged[$key] = $normalized;
        }

        return $this->encryptValues($extensionKey, $merged);
    }

    /**
     * @param array<string, string> $values Decrypted values.
     * @return array<string, string>
     */
    public function encryptValues(string $extensionKey, array $values): array
    {
        foreach ($this->registry->secretFields($extensionKey) as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $plain = (string) $values[$field];
            if ($plain === '') {
                $values[$field] = '';
                continue;
            }
            if ($this->cipher->isEncrypted($plain)) {
                continue;
            }
            $values[$field] = $this->cipher->encrypt($plain);
        }

        return $values;
    }

    /**
     * @param array<string, string> $values Decrypted values for form rendering.
     * @return array<string, string>
     */
    public function maskValuesForDisplay(string $extensionKey, array $values): array
    {
        foreach ($this->registry->secretFields($extensionKey) as $field) {
            if (!array_key_exists($field, $values) || trim((string) $values[$field]) === '') {
                continue;
            }
            $values[$field] = '';
        }

        return $values;
    }

    public function hasStoredSecret(string $extensionKey, string $fieldName, array $decryptedValues): bool
    {
        return $this->registry->isSecret($extensionKey, $fieldName)
            && trim((string) ($decryptedValues[$fieldName] ?? '')) !== '';
    }

    public function maskLabel(string $extensionKey, string $fieldName, array $decryptedValues): string
    {
        if (!$this->hasStoredSecret($extensionKey, $fieldName, $decryptedValues)) {
            return '';
        }

        return $this->cipher->mask((string) $decryptedValues[$fieldName]);
    }

    private function decryptValue(#[\SensitiveParameter] string $value): string
    {
        if ($value === '' || !$this->cipher->isEncrypted($value)) {
            return $value;
        }

        try {
            return $this->cipher->decrypt($value);
        } catch (CipherException) {
            return '';
        }
    }

    private function isMaskPlaceholder(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^[\x{2022}\*\.]{4,}$/u', $trimmed) === 1) {
            return true;
        }

        // CredentialCipher::mask() keeps a short prefix (e.g. "sk-") + bullets.
        return preg_match('/^.{1,5}[\x{2022}\*\.]{4,}$/u', $trimmed) === 1;
    }
}
