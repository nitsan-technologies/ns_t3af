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

/**
 * Feature permission row for AI Access catalogs (T3Ai:* custom_options).
 */
final readonly class FeaturePermissionDescriptor
{
    /**
     * @param list<string> $relevantModules
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $permBase,
        public array $relevantModules,
        public string $group,
        public string $type = 'level',
        public ?string $extension = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     description: string,
     *     permBase: string,
     *     relevantModules: list<string>,
     *     group: string,
     *     type: string,
     *     extension: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'permBase' => $this->permBase,
            'relevantModules' => $this->relevantModules,
            'group' => $this->group,
            'type' => $this->type,
            'extension' => $this->extension,
        ];
    }
}
