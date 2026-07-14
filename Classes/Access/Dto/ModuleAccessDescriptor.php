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
 * Child backend module card for AI Access catalogs (wizard Step 1 / groupMods).
 */
final readonly class ModuleAccessDescriptor
{
    public function __construct(
        public string $label,
        public string $sublabel,
        public string $description,
        public string $color,
        public string $groupMod,
        public string $extension,
        public string $icon = 'actions-extension',
    ) {}

    /**
     * @return array{label: string, sublabel: string, description: string, color: string, groupMod: string, extension: string, icon: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'sublabel' => $this->sublabel,
            'description' => $this->description,
            'color' => $this->color,
            'groupMod' => $this->groupMod,
            'extension' => $this->extension,
            'icon' => $this->icon,
        ];
    }
}
