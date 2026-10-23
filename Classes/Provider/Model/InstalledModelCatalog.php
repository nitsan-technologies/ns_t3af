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

    public static function restrictsAdapter(string $adapterType): bool
    {
        $adapterType = Provider::normalizeAdapterType($adapterType);
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

        $vendor = substr(Provider::normalizeAdapterType($adapterType), strlen('symfony.'));
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

        $ids = $this->ids($adapterType);
        if ($ids === [] || in_array($modelId, $ids, true)) {
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
