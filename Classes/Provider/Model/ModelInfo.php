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

/**
 * One discovered model. Result of merging live `/models` IDs with bundled
 * Symfony AI `ModelCatalog` entries and capability inference.
 *
 * Surfaced to the backend drawer (Adapter change → fetch → populate model
 * select + capability checkboxes) and cached for 24h in
 * `nst3af_provider_models`.
 *
 * @api Returned by {@see ModelDiscoveryServiceInterface}; field names are part
 *      of the JSON contract consumed by `provider-drawer.js`.
 */
final readonly class ModelInfo
{
    /**
     * @param string       $id            Vendor model identifier, e.g. `gpt-4o-mini`.
     * @param string       $label         Human-readable label; falls back to id.
     * @param list<string> $capabilities  Subset of {@see \NITSAN\NsT3AF\Provider\Capability::ALL}.
     * @param string       $source        `live`, `catalog`, `inferred` — origin of metadata; informational.
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $capabilities,
        public string $source,
    ) {}

    /**
     * @return array{id: string, label: string, capabilities: list<string>, source: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'capabilities' => $this->capabilities,
            'source' => $this->source,
        ];
    }
}
