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

namespace NITSAN\NsT3AF\Mcp\Contract;

/**
 * MCP prompt-library CRUD operations (list/create/update via tools).
 *
 * @api Semver-stable for MCP tool dependency injection.
 */
interface AiPromptsMcpOperationsInterface
{
    public function normalizeCategoryId(string $categoryId): string;

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function buildCategoryDetail(string $categoryId, array $filters): array;

    /**
     * @param array<string, mixed> $payload
     */
    public function createPrompt(string $categoryId, array $payload): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePrompt(string $categoryId, int $uid, array $payload): bool;
}
