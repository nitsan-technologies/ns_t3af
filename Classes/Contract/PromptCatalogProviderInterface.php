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
 * Registers prompt categories and catalog data for the AI Prompts backend module.
 */
interface PromptCatalogProviderInterface
{
    public function isAvailable(): bool;

    public function getExtensionKey(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function getCategories(int $storagePid = 0): array;

    public function supportsCategory(string $categoryId): bool;

    public function isReadOnlyCategory(string $categoryId): bool;

    public function resolveCategoryScope(string $categoryId): string;

    public function getSourceTable(string $categoryId): string;

    /**
     * @return array{
     *   available: bool,
     *   scopes: list<array{id: string, label: string}>,
     *   typesByScope: array<string, list<array{id: string, label: string}>>,
     *   variablesByType: array<string, list<string>>,
     *   defaultTextByType: array<string, string>
     * }
     */
    public function buildUiCatalog(): array;

    /**
     * @return list<array{
     *   uid: int,
     *   source: string,
     *   promptType: string,
     *   scope: string,
     *   promptTitle: string,
     *   promptText: string,
     *   isBuiltin: bool,
     *   engineProfile?: string,
     *   resultStyle?: string,
     *   dataSource?: string
     * }>
     */
    public function getPromptRowsForCategory(string $categoryId, int $storagePid = 0): array;

    public function validateGlobalPrompt(string $categoryId, string $promptType, string $scope, string $promptText): ?string;

    public function requiresSiteStorage(string $categoryId): bool;

    public function resolveStoragePidForRecord(string $categoryId, int $storagePid): int;

    public function resolvePromptKind(string $categoryId): string;

    public function isSidebarCategory(string $categoryId): bool;
}
