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

namespace NITSAN\NsT3AF\Updates;

use NITSAN\NsT3AF\Domain\Repository\ProviderRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Removes the pre–per-site unique index on `identifier` alone.
 *
 * Current schema uses UNIQUE(pid, identifier) only; the legacy global unique
 * key blocks importing the same identifier into another site root.
 *
 * @internal
 */
#[UpgradeWizard('nst3afDropLegacyProviderIdentifierUnique')]
final class DropLegacyProviderIdentifierUniqueUpdate implements UpgradeWizardInterface
{
    private const LEGACY_INDEX = 'identifier';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'AI Foundation: drop legacy global provider identifier unique index';
    }

    public function getDescription(): string
    {
        return 'Removes obsolete UNIQUE(identifier) on tx_nst3af_provider so provider'
            . ' identifiers are unique per site root (pid) only, as required for import.';
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(ProviderRepository::TABLE);
        $connection->executeStatement(
            'ALTER TABLE ' . $connection->quoteIdentifier(ProviderRepository::TABLE)
            . ' DROP INDEX ' . $connection->quoteIdentifier(self::LEGACY_INDEX),
        );

        return true;
    }

    public function updateNecessary(): bool
    {
        return $this->hasLegacyIndex();
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [];
    }

    private function hasLegacyIndex(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(ProviderRepository::TABLE);
        $schemaManager = $connection->createSchemaManager();
        $indexes = $schemaManager->listTableIndexes(ProviderRepository::TABLE);

        return isset($indexes[self::LEGACY_INDEX]) && $indexes[self::LEGACY_INDEX]->isUnique();
    }
}
