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

namespace NITSAN\NsT3AF\Contract;

/**
 * Optional hook implemented by child extensions (ns_t3ai, ns_t3cs, …) to supply
 * dynamic provider override dropdowns in the AI Foundation → AI Features drawer.
 *
 * Register implementations with the DI tag {@code t3af.feature_provider_form_options}.
 */
interface FeatureProviderFormOptionsInterface
{
    /**
     * Extension key this implementation serves (e.g. ns_t3ai, ns_t3cs).
     */
    public function getExtensionKey(): string;

    /**
     * Drawer scope + ext_conf field pairs managed by this implementation.
     *
     * @return list<array{scope: string, field: string}>
     */
    public function getManagedFieldBindings(): array;

    /**
     * @return list<array{label: string, value: string, selected: bool, adapterAvailable?: bool, unavailableMessage?: string}>
     */
    public function buildProviderOptions(string $scope, string $fieldName, string $currentValue = 'default'): array;

    public function providerOverrideHint(string $scope, string $fieldName): string;

    /**
     * @return list<string> Allowed values for the given field.
     */
    public function allowedValuesForField(string $fieldName): array;

    /**
     * @return non-empty-string|null Error message when the override cannot be saved.
     */
    public function validateOverrideValue(string $fieldName, string $value): ?string;

    /**
     * Cross-field validation for submitted provider overrides (e.g. embedding + LLM compatibility).
     *
     * @param array<string, string> $submitted
     * @return list<string>
     */
    public function validateSubmittedSettings(array $submitted): array;
}
