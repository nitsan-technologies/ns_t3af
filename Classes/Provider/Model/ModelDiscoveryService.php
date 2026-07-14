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

namespace NITSAN\NsT3AF\Provider\Model;

use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Merges live `/models` IDs, bundled Symfony AI `ModelCatalog` metadata, and
 * capability inference into a single `list<ModelInfo>`. Cached for 24h in
 * `nst3af_provider_models` keyed by provider uid + adapter type.
 *
 * Merge precedence (winning side last):
 *  1. Inferred capabilities (from id heuristics).
 *  2. Catalog capabilities (when the bridge ships a `ModelCatalog`).
 *  3. Live presence on the `/models` endpoint marks a model as `source=live`.
 *
 * Drawer JS treats the returned capabilities as defaults — checkboxes stay
 * editable so a user can override.
 *
 * @internal
 */
final class ModelDiscoveryService implements ModelDiscoveryServiceInterface
{
    public function __construct(
        private readonly LiveModelProbe $liveProbe,
        private readonly SymfonyAiCatalogReader $catalogReader,
        private readonly CapabilityInferrer $inferrer,
        private readonly CacheFacadeInterface $cache,
    ) {}

    public function discover(Provider $provider, bool $refresh = false): array
    {
        $cacheKey = $this->cacheKey($provider);
        if (!$refresh) {
            /** @var list<array{id: string, label: string, capabilities: list<string>, source: string}>|false $cached */
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $this->hydrate($cached);
            }
        }

        $models = $this->merge($provider);

        $this->cache->set(
            $cacheKey,
            array_map(static fn(ModelInfo $m): array => $m->toArray(), $models),
            ['nst3af', 'provider_' . $provider->uid],
        );

        return $models;
    }

    /**
     * @return list<ModelInfo>
     */
    private function merge(Provider $provider): array
    {
        $vendor = $this->vendorKey($provider->adapterType);
        $catalog = $vendor !== '' ? $this->catalogReader->read($vendor) : [];
        $catalogById = [];
        foreach ($catalog as $model) {
            $catalogById[$model->id] = $model;
        }

        $liveIds = $this->liveProbe->probe($provider);

        /** @var array<string, ModelInfo> $merged */
        $merged = [];

        foreach ($liveIds as $id) {
            $caps = $catalogById[$id]->capabilities ?? $this->inferrer->infer($id, $provider->adapterType);
            $merged[$id] = new ModelInfo(
                id: $id,
                label: $catalogById[$id]->label ?? $id,
                capabilities: $caps,
                source: 'live',
            );
        }

        foreach ($catalog as $model) {
            if (isset($merged[$model->id])) {
                continue;
            }
            $caps = $model->capabilities !== []
                ? $model->capabilities
                : $this->inferrer->infer($model->id, $provider->adapterType);
            $merged[$model->id] = new ModelInfo(
                id: $model->id,
                label: $model->label,
                capabilities: $caps,
                source: 'catalog',
            );
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * @param list<array{id: string, label: string, capabilities: list<string>, source: string}> $rows
     * @return list<ModelInfo>
     */
    private function hydrate(array $rows): array
    {
        return array_map(
            static fn(array $r): ModelInfo => new ModelInfo($r['id'], $r['label'], $r['capabilities'], $r['source']),
            $rows,
        );
    }

    private function cacheKey(Provider $provider): string
    {
        $normalizedAdapterType = preg_replace('/[^a-zA-Z0-9_%\\-&]/', '_', $provider->adapterType) ?? 'unknown';

        return sprintf(
            'models_%d_%s',
            $provider->uid,
            $normalizedAdapterType,
        );
    }

    private function vendorKey(string $adapterType): string
    {
        if (str_starts_with($adapterType, 'symfony.')) {
            return substr($adapterType, 8);
        }

        return '';
    }
}
