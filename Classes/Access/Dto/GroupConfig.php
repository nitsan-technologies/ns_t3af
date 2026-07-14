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

namespace NITSAN\NsT3AF\Access\Dto;

use NITSAN\NsT3AF\Access\PayloadBoolean;

/**
 * Wizard state for one backend user group (modules, features, records, limits).
 */
final class GroupConfig
{
    /**
     * @param array<string, bool> $modules
     * @param array<string, string> $features feature key => level string
     * @param array<string, string> $records catalog row id => access level string
     */
    public function __construct(
        public array $modules = [],
        public array $features = [],
        public array $records = [],
        public LimitsConfig $limits = new LimitsConfig(),
        public bool $configured = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $limitsRaw = $data['limits'] ?? [];
        if (!is_array($limitsRaw)) {
            $limitsRaw = [];
        }

        $modules = $data['modules'] ?? [];
        $features = $data['features'] ?? [];
        $records = $data['records'] ?? [];

        return new self(
            modules: is_array($modules)
                ? array_map(static fn($v) => PayloadBoolean::parse($v), $modules)
                : [],
            features: is_array($features) ? array_map(static fn($v) => (string) $v, $features) : [],
            records: is_array($records) ? array_map(static fn($v) => (string) $v, $records) : [],
            limits: LimitsConfig::fromArray($limitsRaw),
            configured: PayloadBoolean::parse($data['configured'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modules' => $this->modules,
            'features' => $this->features,
            'records' => $this->records,
            'limits' => $this->limits->toArray(),
            'configured' => $this->configured,
        ];
    }
}
