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

use NITSAN\NsT3AF\Registry\AiFeatureCardProviderRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsDynamicDefaultsRegistry;

/**
 * Links Quick Setup master toggles to the same persisted fields as AI Features drawers.
 *
 * @internal
 */
final class WizardFeatureToggleService
{
    public function __construct(
        private readonly AiFeatureCardProviderRegistry $featureCardProviderRegistry,
        private readonly ExtensionSettingsDynamicDefaultsRegistry $dynamicDefaultsRegistry,
    ) {}

    /**
     * @param array<string, bool> $toggles
     * @return array<string, string>
     */
    public function expandTogglesForExtension(string $extensionKey, array $toggles): array
    {
        $expansionMap = $this->getExpansionMapForExtension($extensionKey);
        if ($expansionMap === []) {
            $normalized = [];
            foreach ($toggles as $field => $enabled) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                $normalized[$field] = $enabled ? '1' : '0';
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($toggles as $field => $enabled) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $value = $enabled ? '1' : '0';
            $childFields = $expansionMap[$field] ?? [];
            if ($childFields === []) {
                $normalized[$field] = $value;
                continue;
            }

            $normalized[$field] = $value;
            foreach ($childFields as $childField) {
                $normalized[$childField] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $currentValues
     * @param array<string, string> $schemaDefaults
     */
    public function resolveMasterToggleDefault(
        string $extensionKey,
        string $masterField,
        array $childFields,
        array $currentValues,
        array $schemaDefaults,
        int $storagePid = 0,
    ): bool {
        if ($childFields !== []) {
            foreach ($childFields as $childField) {
                if (array_key_exists($childField, $currentValues) && self::isTruthy($currentValues[$childField])) {
                    return true;
                }
            }

            $dynamicDefaults = $this->dynamicDefaultsRegistry->getForExtension($extensionKey, $storagePid);
            foreach ($childFields as $childField) {
                if (isset($dynamicDefaults[$childField]) && self::isTruthy($dynamicDefaults[$childField])) {
                    return true;
                }
            }

            if (array_key_exists($masterField, $currentValues)) {
                return self::isTruthy($currentValues[$masterField]);
            }

            return self::isTruthy($schemaDefaults[$masterField] ?? '1');
        }

        if (array_key_exists($masterField, $currentValues)) {
            return self::isTruthy($currentValues[$masterField]);
        }

        return self::isTruthy($schemaDefaults[$masterField] ?? '1');
    }

    /**
     * @param array<string, mixed> $currentValues
     * @param array<string, array<string, mixed>> $schemaFields
     */
    public function isPersistableField(
        string $extensionKey,
        string $field,
        array $currentValues,
        array $schemaFields,
        int $storagePid = 0,
    ): bool {
        if ($field === '') {
            return false;
        }
        if (array_key_exists($field, $currentValues) || isset($schemaFields[$field])) {
            return true;
        }
        if (str_ends_with($field, 'Feature')) {
            return true;
        }

        $dynamicDefaults = $this->dynamicDefaultsRegistry->getForExtension($extensionKey, $storagePid);
        if (isset($dynamicDefaults[$field])) {
            return true;
        }

        return in_array($field, $this->getAllExpandedChildFieldsForExtension($extensionKey), true);
    }

    /**
     * @return array<string, list<string>>
     */
    private function getExpansionMapForExtension(string $extensionKey): array
    {
        $map = [];
        foreach ($this->featureCardProviderRegistry->getAvailableProviders() as $provider) {
            if ($provider->getExtensionKey() !== $extensionKey) {
                continue;
            }
            foreach ($provider->getFeatureCards() as $descriptor) {
                if (!$descriptor->wizardEligible || $descriptor->wizardToggleField === null || $descriptor->wizardToggleField === '') {
                    continue;
                }
                if ($descriptor->wizardToggleChildFields === []) {
                    continue;
                }
                $map[$descriptor->wizardToggleField] = $descriptor->wizardToggleChildFields;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function getAllExpandedChildFieldsForExtension(string $extensionKey): array
    {
        $fields = [];
        foreach ($this->getExpansionMapForExtension($extensionKey) as $childFields) {
            foreach ($childFields as $childField) {
                $fields[] = $childField;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return list<string>
     */
    public function childFieldsForMaster(string $extensionKey, string $masterField): array
    {
        return $this->getExpansionMapForExtension($extensionKey)[$masterField] ?? [];
    }

    /**
     * Normalizes wizard toggle payloads. Avoid plain `(bool) $value` — in PHP
     * `(bool) "0"` and `(bool) "false"` are true.
     */
    public static function normalizeToggleEnabled(mixed $value): bool
    {
        return self::isTruthy($value);
    }

    private static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === '1' || $normalized === 'true' || $normalized === 'on' || $normalized === 'yes';
    }
}
