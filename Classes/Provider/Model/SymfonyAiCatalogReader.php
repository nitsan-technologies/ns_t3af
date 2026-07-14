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

use NITSAN\NsT3AF\Provider\Capability;

/**
 * Reads bundled `Symfony\AI\Platform\Bridge\<Vendor>\ModelCatalog` (or the
 * `Lochmueller\SealAi\Bridge\<Vendor>\ModelCatalog` re-export) when installed.
 *
 * Returns one {@see ModelInfo} per advertised model. Capability mapping is
 * heuristic: Symfony AI exposes capability classes (`Vision`, `ToolCall`,
 * `Embedding`, …) per model, but their FQCNs change between bridge releases,
 * so we string-match the class basenames rather than relying on class_exists.
 *
 * (GPL-2.0-or-later).
 *
 * @internal
 */
class SymfonyAiCatalogReader
{
    /**
     * Symfony bridge namespaces do not always match {@see pascalVendor()} output
     * (e.g. vendor key `openai` → `OpenAi`, not `Openai`).
     *
     * @var array<string, string>
     */
    private const BRIDGE_NAMESPACE_BY_VENDOR = [
        'openai' => 'OpenAi',
        'openresponses' => 'OpenResponses',
        'open-responses' => 'OpenResponses',
    ];

    /**
     * @return list<ModelInfo> Empty when catalog class is missing or unreadable.
     */
    public function read(string $vendorKey): array
    {
        $catalogClass = $this->resolveCatalogClass($vendorKey);
        if ($catalogClass === null) {
            return [];
        }

        try {
            $catalog = new $catalogClass();
        } catch (\Throwable) {
            return [];
        }

        if (!method_exists($catalog, 'getModels')) {
            return [];
        }

        try {
            /** @var array<string, mixed> $entries */
            $entries = $catalog->getModels();
        } catch (\Throwable) {
            return [];
        }

        $models = [];
        foreach ($entries as $id => $entry) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $models[] = new ModelInfo(
                id: $id,
                label: $id,
                capabilities: $this->capabilitiesForEntry($entry),
                source: 'catalog',
            );
        }

        return $models;
    }

    private function resolveCatalogClass(string $vendorKey): ?string
    {
        $pascalCandidates = [];
        if (isset(self::BRIDGE_NAMESPACE_BY_VENDOR[$vendorKey])) {
            $pascalCandidates[] = self::BRIDGE_NAMESPACE_BY_VENDOR[$vendorKey];
        }
        $pascalCandidates[] = $this->pascalVendor($vendorKey);

        foreach (array_values(array_unique($pascalCandidates)) as $pascal) {
            foreach ([
                sprintf('Symfony\\AI\\Platform\\Bridge\\%s\\ModelCatalog', $pascal),
                sprintf('Lochmueller\\SealAi\\Bridge\\%s\\ModelCatalog', $pascal),
            ] as $candidate) {
                // Try the original FQN (Composer mode) and the php-scoper-prefixed
                // variant shipped inside t3af.phar (classic mode).
                foreach ([$candidate, 'NITSAN\T3af\\Vendor\\' . $candidate] as $fqcn) {
                    if (class_exists($fqcn)) {
                        return $fqcn;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  mixed $entry Raw catalog entry; shape varies by bridge version.
     * @return list<string>
     */
    private function capabilitiesForEntry(mixed $entry): array
    {
        if ($this->isEmbeddingModelClass($entry)) {
            return [Capability::EMBEDDINGS];
        }

        return $this->extractCapabilities($entry);
    }

    private function isEmbeddingModelClass(mixed $entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }
        $class = $entry['class'] ?? '';
        if (!is_string($class) || $class === '') {
            return false;
        }

        return str_ends_with($class, '\\Embeddings');
    }

    /**
     * @param  mixed $entry Raw catalog entry; shape varies by bridge version.
     * @return list<string>
     */
    private function extractCapabilities(mixed $entry): array
    {
        $rawCaps = [];
        if (is_array($entry)) {
            $rawCaps = $entry['capabilities'] ?? $entry[1] ?? [];
        } elseif (is_object($entry)) {
            if (property_exists($entry, 'capabilities')) {
                /** @var mixed $rawCaps */
                $rawCaps = $entry->capabilities;
            } elseif (method_exists($entry, 'getCapabilities')) {
                /** @var mixed $rawCaps */
                $rawCaps = $entry->getCapabilities();
            }
        }
        if (!is_iterable($rawCaps)) {
            return [];
        }

        $caps = [];
        foreach ($rawCaps as $cap) {
            $name = $this->capabilityName($cap);
            $mapped = $this->mapCapabilityName($name);
            if ($mapped !== null) {
                $caps[] = $mapped;
            }
        }

        return array_values(array_unique($caps));
    }

    private function capabilityName(mixed $cap): string
    {
        if (is_string($cap)) {
            return strtolower($cap);
        }
        if (is_object($cap)) {
            $class = $cap::class;
            $base = substr($class, (int) (strrpos($class, '\\') ?: -1) + 1);

            return strtolower($base);
        }

        return '';
    }

    private function mapCapabilityName(string $name): ?string
    {
        return match (true) {
            str_contains($name, 'embed') => Capability::EMBEDDINGS,
            str_contains($name, 'vision'), str_contains($name, 'image') => Capability::VISION,
            str_contains($name, 'tool'), str_contains($name, 'function') => Capability::TOOL_USE,
            str_contains($name, 'stream') => Capability::STREAMING,
            str_contains($name, 'chat'), str_contains($name, 'conversation') => Capability::CHAT,
            str_contains($name, 'completion'), str_contains($name, 'text') => Capability::COMPLETION,
            default => null,
        };
    }

    private function pascalVendor(string $vendorKey): string
    {
        $parts = explode('-', $vendorKey);
        $parts = array_map(static fn(string $p): string => ucfirst($p), $parts);

        return implode('', $parts);
    }
}
