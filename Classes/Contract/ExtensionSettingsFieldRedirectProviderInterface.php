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

namespace NITSAN\NsT3AF\Contract;

/**
 * Redirects selected AI Features drawer fields to an external store (e.g. site.yaml).
 *
 * Register with DI tag {@code t3af.extension_settings_field_redirect}.
 *
 * @api
 */
interface ExtensionSettingsFieldRedirectProviderInterface
{
    public function getExtensionKey(): string;

    /**
     * @return list<array{scope: string, field: string}>
     */
    public function getRedirectedFields(): array;

    public function resolveFieldValue(string $scope, string $field, int $storagePid): string;

    /**
     * @return bool true when handled (skip tx_nst3af_extension_setting persistence for this field)
     */
    public function persistFieldValue(string $scope, string $field, string $value, int $storagePid): bool;
}
