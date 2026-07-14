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

use NITSAN\NsT3AF\Access\Dto\FeatureAccessBindingsDescriptor;
use NITSAN\NsT3AF\Access\Dto\FeaturePermissionDescriptor;
use NITSAN\NsT3AF\Access\Dto\ModuleAccessDescriptor;
use NITSAN\NsT3AF\Access\Dto\RecordPermissionDescriptor;

/**
 * Registers AI Access module cards, feature bits, record ACL rows, and runtime bindings.
 */
interface AiAccessCatalogProviderInterface
{
    public function isAvailable(): bool;

    public function getExtensionKey(): string;

    /** Wizard module id (e.g. t3ai, t3cs). Multiple providers may share one key (ns_t3as → t3cs). */
    public function getCatalogModuleKey(): string;

    /** Null when permissions ride on another module (e.g. ns_t3as on t3cs). */
    public function getModuleAccess(): ?ModuleAccessDescriptor;

    /**
     * @return list<FeaturePermissionDescriptor>
     */
    public function getFeaturePermissions(): array;

    /**
     * @return list<RecordPermissionDescriptor>
     */
    public function getRecordPermissions(): array;

    /**
     * Null when this provider only contributes records/features for another module's bindings.
     */
    public function getFeatureAccessBindings(): ?FeatureAccessBindingsDescriptor;
}
