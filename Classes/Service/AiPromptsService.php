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

use NITSAN\NsT3AF\Contract\PromptDefaultsSyncProviderInterface;
use NITSAN\NsT3AF\Mcp\Contract\AiPromptsMcpOperationsInterface;
use NITSAN\NsT3AF\Prompt\AiPromptRepository;
use NITSAN\NsT3AF\Registry\PromptCatalogProviderRegistry;

final class AiPromptsService implements AiPromptsMcpOperationsInterface, PromptOverviewProviderInterface
{
    /**
     * @param iterable<PromptDefaultsSyncProviderInterface> $defaultsSyncProviders
     */
    public function __construct(
        private readonly PromptCatalogProviderRegistry $providerRegistry,
        private readonly AiPromptRepository $aiPromptRepository,
        private readonly iterable $defaultsSyncProviders = [],
    ) {}

    public function getCatalogForUi(): array
    {
        $available = false;
        $byCategory = [];
        $scopes = [];
        $typesByScope = [];
        $variablesByType = [];
        $defaultTextByType = [];

        foreach ($this->providerRegistry->getAvailableProviders() as $provider) {
            $catalog = $provider->buildUiCatalog();
            if ($catalog['available'] ?? false) {
                $available = true;
            }

            foreach ($provider->getCategories() as $category) {
                $categoryId = (string) $category['id'];
                $scope = $provider->resolveCategoryScope($categoryId);
                if ($catalog['available'] ?? false) {
                    $scopeExists = is_string($scope)
                        && $scope !== ''
                        && isset(($catalog['typesByScope'] ?? [])[$scope]);

                    // Some categories (e.g. RTE) intentionally expose multiple scopes.
                    // If provider scope does not map to a catalog scope key, keep the
                    // full category catalog instead of slicing to an empty set.
                    $byCategory[$categoryId] = $scopeExists
                        ? $this->sliceCatalogForScope($catalog, $scope)
                        : [
                            'available' => (bool) ($catalog['available'] ?? false),
                            'scopes' => $catalog['scopes'] ?? [],
                            'typesByScope' => $catalog['typesByScope'] ?? [],
                            'variablesByType' => $catalog['variablesByType'] ?? [],
                            'defaultTextByType' => $catalog['defaultTextByType'] ?? [],
                        ];
                }
            }

            $scopes = array_merge($scopes, $catalog['scopes'] ?? []);
            $typesByScope = array_merge($typesByScope, $catalog['typesByScope'] ?? []);
            $variablesByType = array_merge($variablesByType, $catalog['variablesByType'] ?? []);
            $defaultTextByType = array_merge($defaultTextByType, $catalog['defaultTextByType'] ?? []);
        }

        return [
            'available' => $available,
            'scopes' => array_values($scopes),
            'typesByScope' => $typesByScope,
            'variablesByType' => $variablesByType,
            'defaultTextByType' => $defaultTextByType,
            'byCategory' => $byCategory,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function validateGlobalPromptPayload(string $categoryId, array $payload): ?string
    {
        if ($this->providerRegistry->isSidebarCategory($categoryId)) {
            return null;
        }

        $promptType = trim((string) ($payload['promptType'] ?? ''));
        $scope = trim((string) ($payload['scope'] ?? ''));
        $promptText = trim((string) ($payload['promptText'] ?? ''));

        if ($promptType === '' || $scope === '') {
            return 'invalid_prompt_type';
        }

        $provider = $this->providerRegistry->findProviderForCategory($categoryId);

        return $provider?->validateGlobalPrompt($categoryId, $promptType, $scope, $promptText);
    }

    public function normalizeCategoryId(string $categoryId): string
    {
        return $categoryId;
    }

    /**
     * @param array{search:string,extension:string,title:string,text:string,promptType:string,scope:string} $filters
     * @return array{
     *   categories:list<array<string, mixed>>,
     *   kpis:array{categoryCount:int,totalPrompts:int,extensionCount:int,customPrompts:int},
     *   extensions:list<array{key:string,labelKey:string}>
     * }
     */
    public function buildOverviewData(array $filters, int $storagePid = 0): array
    {
        if (!$this->isPromptStorageAvailable()) {
            return $this->emptyOverviewData();
        }

        $categories = $this->buildCategories($storagePid);

        $totalPrompts = 0;
        $customPrompts = 0;
        foreach ($categories as $category) {
            $totalPrompts += (int) $category['promptCount'];
            $customPrompts += (int) ($category['customPromptCount'] ?? 0);
        }
        $extensionKeys = array_values(array_unique(array_filter(array_map(
            static fn(array $category): string => (string) ($category['providerExtension'] ?? ''),
            $categories,
        ))));
        sort($extensionKeys);
        $extensions = array_map(
            static fn(string $extensionKey): array => [
                'key' => $extensionKey,
                'labelKey' => 'module.aiPrompts.extension.' . $extensionKey,
            ],
            $extensionKeys,
        );

        return [
            'categories' => $categories,
            'kpis' => [
                'categoryCount' => count($categories),
                'totalPrompts' => $totalPrompts,
                'extensionCount' => count($extensions),
                'customPrompts' => $customPrompts,
            ],
            'extensions' => $extensions,
        ];
    }

    /**
     * @param array{title:string,text:string,promptType:string,scope:string} $filters
     * @return array{category:array<string, mixed>, rows:list<array<string, mixed>>}
     */
    public function buildCategoryDetail(string $categoryId, array $filters, int $storagePid = 0): array
    {
        if (!$this->isPromptStorageAvailable()) {
            return [
                'category' => $this->emptyCategory($categoryId),
                'rows' => [],
            ];
        }

        $category = $this->resolveCategory($categoryId, $storagePid);
        $rows = $this->loadCategoryRows($category['id'], $storagePid);

        $titleFilter = mb_strtolower(trim($filters['title']));
        $textFilter = mb_strtolower(trim($filters['text']));
        $typeFilter = trim($filters['promptType']);
        $scopeFilter = trim($filters['scope']);

        $rows = array_values(array_filter($rows, static function (array $row) use ($titleFilter, $textFilter, $typeFilter, $scopeFilter): bool {
            if ($titleFilter !== '' && !str_contains(mb_strtolower((string) $row['promptTitle']), $titleFilter)) {
                return false;
            }
            if ($textFilter !== '' && !str_contains(mb_strtolower((string) $row['promptText']), $textFilter)) {
                return false;
            }
            if ($typeFilter !== '' && $typeFilter !== 'all' && (string) $row['promptType'] !== $typeFilter) {
                return false;
            }
            if ($scopeFilter !== '' && $scopeFilter !== 'all' && (string) $row['scope'] !== $scopeFilter) {
                return false;
            }

            return true;
        }));

        return [
            'category' => $category,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createPrompt(string $categoryId, array $payload): bool
    {
        if ($this->providerRegistry->isReadOnlyCategory($categoryId) || !$this->isMutableStorageAvailable()) {
            return false;
        }

        $provider = $this->providerRegistry->findProviderForCategory($categoryId);
        if ($provider === null) {
            return false;
        }

        $title = trim((string) ($payload['promptTitle'] ?? ''));
        $text = trim((string) ($payload['promptText'] ?? ''));
        if ($title === '' || $text === '') {
            return false;
        }

        if ($this->validateGlobalPromptPayload($categoryId, $payload) !== null) {
            return false;
        }

        $storagePid = max(0, (int) ($payload['storagePid'] ?? 0));
        $recordStoragePid = $this->providerRegistry->resolveStoragePidForRecord($categoryId, $storagePid);
        if ($this->providerRegistry->requiresSiteStorage($categoryId) && $recordStoragePid <= 0) {
            return false;
        }

        $values = [
            'pid' => $recordStoragePid,
            'tstamp' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
            'crdate' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
            'hidden' => 0,
            'deleted' => 0,
            'extension_key' => $provider->getExtensionKey(),
            'category_id' => $categoryId,
            'prompt_kind' => $this->providerRegistry->resolvePromptKind($categoryId),
            'prompt_title' => $title,
            'prompt_text' => $text,
            'scope' => '',
            'prompt_type' => '',
            'is_default' => 0,
        ];

        if (!$this->providerRegistry->isSidebarCategory($categoryId)) {
            $scope = trim((string) ($payload['scope'] ?? $provider->resolveCategoryScope($categoryId)));
            $promptType = trim((string) ($payload['promptType'] ?? ''));
            if ($scope === '' || $promptType === '') {
                return false;
            }
            $values['scope'] = $scope;
            $values['prompt_type'] = $promptType;
        }

        return $this->aiPromptRepository->insert($values);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePrompt(string $categoryId, int $uid, array $payload): bool
    {
        if ($this->providerRegistry->isReadOnlyCategory($categoryId) || !$this->isMutableStorageAvailable()) {
            return false;
        }

        if ($uid <= 0) {
            return false;
        }

        $provider = $this->providerRegistry->findProviderForCategory($categoryId);
        if ($provider === null) {
            return false;
        }

        $title = trim((string) ($payload['promptTitle'] ?? ''));
        $text = trim((string) ($payload['promptText'] ?? ''));
        if ($title === '' || $text === '') {
            return false;
        }

        if ($this->validateGlobalPromptPayload($categoryId, $payload) !== null) {
            return false;
        }

        $storagePid = max(0, (int) ($payload['storagePid'] ?? 0));
        $recordStoragePid = $this->providerRegistry->resolveStoragePidForRecord($categoryId, $storagePid);
        if (!$this->providerRegistry->isSidebarCategory($categoryId)
            && !$this->aiPromptRepository->recordBelongsToStorage($uid, $recordStoragePid, $provider->getExtensionKey())
        ) {
            return false;
        }

        $values = [
            'tstamp' => (int) ($GLOBALS['EXEC_TIME'] ?? time()),
            'prompt_title' => $title,
            'prompt_text' => $text,
            'pid' => $recordStoragePid,
        ];

        if (!$this->providerRegistry->isSidebarCategory($categoryId)) {
            $scope = trim((string) ($payload['scope'] ?? $provider->resolveCategoryScope($categoryId)));
            $promptType = trim((string) ($payload['promptType'] ?? ''));
            if ($scope === '' || $promptType === '') {
                return false;
            }
            $values['scope'] = $scope;
            $values['prompt_type'] = $promptType;
        }

        return $this->aiPromptRepository->update($uid, $values);
    }

    public function deletePrompt(string $categoryId, int $uid, int $storagePid = 0): bool
    {
        if ($this->providerRegistry->isReadOnlyCategory($categoryId) || !$this->isMutableStorageAvailable()) {
            return false;
        }

        if ($uid <= 0) {
            return false;
        }

        $provider = $this->providerRegistry->findProviderForCategory($categoryId);
        if ($provider === null) {
            return false;
        }

        $recordStoragePid = $this->providerRegistry->resolveStoragePidForRecord($categoryId, $storagePid);
        if (!$this->providerRegistry->isSidebarCategory($categoryId)
            && !$this->aiPromptRepository->recordBelongsToStorage($uid, $recordStoragePid, $provider->getExtensionKey())
        ) {
            return false;
        }

        return $this->aiPromptRepository->softDelete($uid);
    }

    /**
     * @return array{created:int}
     */
    public function synchronizeDefaults(int $storagePid = 0): array
    {
        if (!$this->isMutableStorageAvailable() || $storagePid <= 0) {
            return ['created' => 0];
        }

        $created = 0;
        foreach ($this->defaultsSyncProviders as $syncProvider) {
            if (!$syncProvider->isAvailable()) {
                continue;
            }
            $created += $syncProvider->synchronizeDefaults($storagePid)['created'] ?? 0;
        }

        return ['created' => $created];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCategory(string $categoryId, int $storagePid = 0): array
    {
        foreach ($this->buildCategories($storagePid) as $category) {
            if ($category['id'] === $categoryId) {
                return $category;
            }
        }

        return $this->emptyCategory($categoryId);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCategory(string $categoryId): array
    {
        return [
            'id' => $categoryId,
            'title' => '',
            'extension' => '',
            'description' => '',
            'promptCount' => 0,
            'scopeCount' => 0,
            'manageLabel' => '',
            'sourceTable' => AiPromptRepository::TABLE,
        ];
    }

    /**
     * @return array{categories:list<array<string, mixed>>, kpis:array<string, int>, extensions:list<array{key:string,labelKey:string}>}
     */
    private function emptyOverviewData(): array
    {
        return [
            'categories' => [],
            'kpis' => [
                'categoryCount' => 0,
                'totalPrompts' => 0,
                'extensionCount' => 0,
                'customPrompts' => 0,
            ],
            'extensions' => [],
        ];
    }

    private function isPromptStorageAvailable(): bool
    {
        return $this->aiPromptRepository->isTableRegistered()
            || $this->providerRegistry->getAvailableProviders() !== [];
    }

    private function isMutableStorageAvailable(): bool
    {
        return $this->aiPromptRepository->isTableRegistered();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildCategories(int $storagePid = 0): array
    {
        if (!$this->isPromptStorageAvailable()) {
            return [];
        }

        $categories = [];
        foreach ($this->providerRegistry->getAvailableProviders() as $provider) {
            $providerExtension = $provider->getExtensionKey();
            foreach ($provider->getCategories($storagePid) as $category) {
                $category['providerExtension'] = $providerExtension;
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCategoryRows(string $categoryId, int $storagePid = 0): array
    {
        $provider = $this->providerRegistry->findProviderForCategory($categoryId);

        return $provider?->getPromptRowsForCategory($categoryId, $storagePid) ?? [];
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function sliceCatalogForScope(array $catalog, string $scope): array
    {
        $types = $catalog['typesByScope'][$scope] ?? [];
        $typeIds = array_map(static fn(array $entry): string => (string) $entry['id'], $types);
        $variablesByType = [];
        $defaultTextByType = [];
        foreach ($typeIds as $typeId) {
            if (isset($catalog['variablesByType'][$typeId])) {
                $variablesByType[$typeId] = $catalog['variablesByType'][$typeId];
            }
            if (isset($catalog['defaultTextByType'][$typeId])) {
                $defaultTextByType[$typeId] = $catalog['defaultTextByType'][$typeId];
            }
        }

        $scopeEntry = null;
        foreach ($catalog['scopes'] as $entry) {
            if (($entry['id'] ?? '') === $scope) {
                $scopeEntry = $entry;
                break;
            }
        }

        return [
            'available' => (bool) ($catalog['available'] ?? false),
            'scopes' => $scopeEntry !== null
                ? [$scopeEntry]
                : [['id' => $scope, 'label' => ucfirst($scope)]],
            'typesByScope' => [$scope => $types],
            'variablesByType' => $variablesByType,
            'defaultTextByType' => $defaultTextByType,
        ];
    }
}
