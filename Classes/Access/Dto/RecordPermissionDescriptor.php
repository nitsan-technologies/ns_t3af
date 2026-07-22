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
 * Record-level permission row for AI Access catalogs (tables_select / tables_modify).
 *
 * @api
 */
final readonly class RecordPermissionDescriptor
{
    /**
     * @param list<string> $tables
     * @param list<string> $relevantModules
     * @param list<string> $relevantFeatures
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $tables,
        public array $relevantModules,
        public array $relevantFeatures = [],
        public string $readHelp = '',
        public string $writeHelp = '',
        public ?string $extension = null,
        public bool $readOnlyWrite = false,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     tables: list<string>,
     *     relevantModules: list<string>,
     *     relevantFeatures: list<string>,
     *     readHelp: string,
     *     writeHelp: string,
     *     extension: string|null,
     *     readOnlyWrite: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'tables' => $this->tables,
            'relevantModules' => $this->relevantModules,
            'relevantFeatures' => $this->relevantFeatures,
            'readHelp' => $this->readHelp,
            'writeHelp' => $this->writeHelp,
            'extension' => $this->extension,
            'readOnlyWrite' => $this->readOnlyWrite,
        ];
    }
}
