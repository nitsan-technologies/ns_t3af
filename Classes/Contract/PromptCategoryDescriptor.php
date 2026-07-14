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
 * Metadata for one AI Prompts module category card.
 */
final class PromptCategoryDescriptor
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $extensionKey,
        public readonly string $description,
        public readonly string $manageLabel,
        public readonly string $sourceTable,
        public readonly bool $readOnly = false,
        public readonly string $scope = '',
    ) {}

    /**
     * @return array{
     *   id: string,
     *   title: string,
     *   extension: string,
     *   description: string,
     *   manageLabel: string,
     *   sourceTable: string,
     *   readOnly?: bool,
     *   scope?: string
     * }
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'extension' => $this->extensionKey,
            'description' => $this->description,
            'manageLabel' => $this->manageLabel,
            'sourceTable' => $this->sourceTable,
        ];
        if ($this->readOnly) {
            $data['readOnly'] = true;
        }
        if ($this->scope !== '') {
            $data['scope'] = $this->scope;
        }

        return $data;
    }
}
