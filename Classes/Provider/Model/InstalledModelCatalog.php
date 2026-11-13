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

namespace NITSAN\NsT3AF\Provider\Model;

use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Names the installed Symfony AI library can actually route.
 *
 * A provider's live /models API can advertise ids the shipped catalog does not
 * know (for example mistral-medium-3.5). Chat then throws "No provider found
 * for model". Catalog-backed adapters may only offer and save catalog ids.
 * Azure deployments, Ollama tags, and custom endpoints stay free text.
 *
 * @internal
 */
final class InstalledModelCatalog
{
    public function __construct(
        private readonly SymfonyAiCatalogReader $reader = new SymfonyAiCatalogReader(),
    ) {}

    public function restricts(string $adapterType): bool
    {
        return self::restrictsAdapter($adapterType);
    }

    /**
     * Stability AI image modes (ns_t3ai) — not Symfony-catalog backed, but still a fixed set.
     *
     * @var list<string>
     */
    private const STABILITY_AI_MODELS = ['core', 'sd3', 'ultra'];

    public static function restrictsAdapter(string $adapterType): bool
    {
        $adapterType = Provider::normalizeAdapterType($adapterType);
        if ($adapterType === 'ns_t3ai.stability_ai') {
            return true;
        }
        if (!str_starts_with($adapterType, 'symfony.')) {
            return false;
        }

        return !in_array($adapterType, [
            Provider::ADAPTER_SYMFONY_AZURE,
            Provider::ADAPTER_SYMFONY_OLLAMA,
            Provider::ADAPTER_SYMFONY_OPENRESPONSES,
            'symfony.huggingface',
        ], true);
    }

    /**
     * @return list<string>
     */
    public function ids(string $adapterType): array
    {
        if (!$this->restricts($adapterType)) {
            return [];
        }

        $adapterType = Provider::normalizeAdapterType($adapterType);
        if ($adapterType === 'ns_t3ai.stability_ai') {
            return self::STABILITY_AI_MODELS;
        }

        $vendor = substr($adapterType, strlen('symfony.'));
        $ids = [];
        foreach ($this->reader->read($vendor) as $model) {
            if ($model->id !== '') {
                $ids[] = $model->id;
            }
        }

        return $ids;
    }

    /**
     * Null when the id may be saved. Empty ids are allowed.
     * A catalog that is not installed does not block the save.
     */
    public function rejection(string $adapterType, string $modelId): ?string
    {
        $modelId = trim($modelId);
        if ($modelId === '' || !$this->restricts($adapterType)) {
            return null;
        }

        $adapterType = Provider::normalizeAdapterType($adapterType);
        $ids = $this->ids($adapterType);
        if ($ids === []) {
            return null;
        }
        // Stability modes are case-insensitive (platform normalizes to lowercase).
        if ($adapterType === 'ns_t3ai.stability_ai') {
            $modelId = strtolower($modelId);
        }
        if (in_array($modelId, $ids, true)) {
            return null;
        }

        return self::unsupportedMessage($modelId);
    }

    /**
     * @param list<string> $listedIds
     */
    public function rejectionAgainstListedIds(string $modelId, array $listedIds): ?string
    {
        $modelId = trim($modelId);
        if ($modelId === '' || $listedIds === [] || in_array($modelId, $listedIds, true)) {
            return null;
        }

        return self::unsupportedMessage($modelId);
    }

    public static function unsupportedMessage(string $modelId): string
    {
        return sprintf('Model "%s" is not supported by this provider.', trim($modelId));
    }
}
