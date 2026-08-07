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
 * Metadata for one MCP Tools backend extension card.
 *
 * @api
 */
final class McpToolsExtensionCardDescriptor
{
    /**
     * @param list<string> $tools
     */
    public function __construct(
        public readonly string $label,
        public readonly string $icon = '🧩',
        public readonly string $iconIdentifier = 'actions-extension',
        public readonly string $iconBg = '#f3f4f6',
        public readonly string $color = '#737373',
        public readonly string $tagline = '',
        public readonly string $skillName = '',
        public readonly string $skillTrigger = '',
        public readonly string $skillFile = '',
        public readonly string $skillDesc = '',
        public readonly string $toolPrefix = '',
        public readonly array $tools = [],
        public readonly bool $showWhenNotLoaded = false,
        public readonly int $sortPriority = 50,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'label' => $this->label,
            'icon' => $this->icon,
            'iconIdentifier' => $this->iconIdentifier,
            'iconBg' => $this->iconBg,
            'color' => $this->color,
            'tagline' => $this->tagline,
            'skillName' => $this->skillName !== '' ? $this->skillName : $this->label,
            'skillTrigger' => $this->skillTrigger,
            'skillFile' => $this->skillFile,
            'skillDesc' => $this->skillDesc,
            'toolPrefix' => $this->toolPrefix,
            'sortPriority' => $this->sortPriority,
            'showWhenNotLoaded' => $this->showWhenNotLoaded,
        ];

        if ($this->tools !== []) {
            $data['tools'] = $this->tools;
        }

        return $data;
    }
}
