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
 * Declares drawer scopes and composite/palette field rules for AI Features settings.
 */
interface ExtensionSettingsScopeProviderInterface
{
    public function isAvailable(): bool;

    public function getExtensionKey(): string;

    /**
     * @return list<string>
     */
    public function getAllowedScopes(): array;

    /**
     * @return array<string, list<string>>
     */
    public function getCompositeScopeCategories(): array;

    /**
     * @return array<string, list<array{id: string, label: string, scope: string}>>
     */
    public function getPaletteScopes(): array;

    /**
     * @return array<string, list<array{category: string, fields: list<string>, exclude?: list<string>}>>
     */
    public function getFieldFilterScopes(): array;

    public function getSaveSuccessMessageKey(): string;

    public function getUnavailableLabelKey(): string;
}
