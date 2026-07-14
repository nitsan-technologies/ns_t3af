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
 * Declares AI Features scopes that support per-scope brand context profile overrides.
 *
 * @api
 */
interface BrandContextFeatureScopeProviderInterface
{
    public function isAvailable(): bool;

    public function getExtensionKey(): string;

    public function supportsScope(string $scope): bool;

    /**
     * @return list<string>
     */
    public function getSupportedScopes(): array;

    /**
     * @return array<string, string> scope id => locallang label key (without LLL prefix)
     */
    public function getScopeLabelKeys(): array;
}
