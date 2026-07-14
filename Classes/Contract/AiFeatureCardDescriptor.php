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
 * Metadata for one AI Features overview card.
 */
final class AiFeatureCardDescriptor
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $subtitle,
        public readonly string $extKey,
        public readonly string $settingsScope,
        public readonly string $icon,
        public readonly string $iconBg,
        public readonly string $iconColor,
        public readonly array $tags,
        public readonly string $description = '',
        public readonly string $descriptionLll = '',
        public readonly ?string $displayExtKey = null,
        public readonly ?string $configExtKey = null,
        public readonly ?string $requiredBackendModule = null,
        public readonly int $sortPriority = 50,
        public readonly bool $wizardEligible = false,
        public readonly ?string $wizardToggleField = null,
        /** @var list<string> */
        public readonly array $wizardToggleChildFields = [],
        public readonly string $wizardGroup = 'feature',
    ) {}

    /**
     * @return array{
     *   id: string,
     *   name: string,
     *   subtitle: string,
     *   extKey: string,
     *   settingsScope: string,
     *   icon: string,
     *   iconBg: string,
     *   iconColor: string,
     *   tags: list<string>,
     *   description?: string,
     *   descriptionLll?: string,
     *   displayExtKey?: string,
     *   configExtKey?: string,
     *   wizardEligible?: bool,
     *   wizardToggleField?: string,
     *   wizardToggleChildFields?: list<string>,
     *   wizardGroup?: string
     * }
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'extKey' => $this->extKey,
            'settingsScope' => $this->settingsScope,
            'icon' => $this->icon,
            'iconBg' => $this->iconBg,
            'iconColor' => $this->iconColor,
            'tags' => $this->tags,
        ];

        if ($this->description !== '') {
            $data['description'] = $this->description;
        }
        if ($this->descriptionLll !== '') {
            $data['descriptionLll'] = $this->descriptionLll;
        }
        if ($this->displayExtKey !== null && $this->displayExtKey !== '') {
            $data['displayExtKey'] = $this->displayExtKey;
        }
        if ($this->configExtKey !== null && $this->configExtKey !== '') {
            $data['configExtKey'] = $this->configExtKey;
        }
        if ($this->wizardEligible) {
            $data['wizardEligible'] = true;
        }
        if ($this->wizardToggleField !== null && $this->wizardToggleField !== '') {
            $data['wizardToggleField'] = $this->wizardToggleField;
        }
        if ($this->wizardGroup !== 'feature') {
            $data['wizardGroup'] = $this->wizardGroup;
        }

        return $data;
    }
}
