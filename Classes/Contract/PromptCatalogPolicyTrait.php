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

use NITSAN\NsT3AF\Prompt\AiPromptRepository;

/**
 * Default prompt storage policy helpers for {@see PromptCatalogProviderInterface}.
 */
trait PromptCatalogPolicyTrait
{
    public function requiresSiteStorage(string $categoryId): bool
    {
        return !$this->isSidebarCategory($categoryId);
    }

    public function resolveStoragePidForRecord(string $categoryId, int $storagePid): int
    {
        if ($this->isSidebarCategory($categoryId)) {
            return 0;
        }

        return max(0, $storagePid);
    }

    public function resolvePromptKind(string $categoryId): string
    {
        if ($this->isSidebarCategory($categoryId)) {
            return AiPromptRepository::KIND_SIDEBAR;
        }

        if ($categoryId === 'rte') {
            return AiPromptRepository::KIND_RTE;
        }

        return AiPromptRepository::KIND_GLOBAL;
    }

    public function isSidebarCategory(string $categoryId): bool
    {
        return $categoryId === 'sidebar';
    }
}
