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

use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Drops the obsolete category column from MCP prompt templates.
 *
 * @internal
 */
#[UpgradeWizard('nst3afDropMcpPromptTemplateCategory')]
final class DropMcpPromptTemplateCategoryUpdate implements UpgradeWizardInterface
{
    private const COLUMN = 'category';
    private const INDEX = 'category';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'AI Foundation: drop category column from MCP prompt templates';
    }

    public function getDescription(): string
    {
        return 'Removes the unused category field from tx_nst3af_mcp_prompt_template.';
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(McpPromptTemplateRepository::TABLE);
        $table = $connection->quoteIdentifier(McpPromptTemplateRepository::TABLE);

        if ($this->hasIndex()) {
            $connection->executeStatement(
                'ALTER TABLE ' . $table . ' DROP INDEX ' . $connection->quoteIdentifier(self::INDEX),
            );
        }

        if ($this->hasColumn()) {
            $connection->executeStatement(
                'ALTER TABLE ' . $table . ' DROP COLUMN ' . $connection->quoteIdentifier(self::COLUMN),
            );
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        return $this->hasColumn();
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [];
    }

    private function hasColumn(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(McpPromptTemplateRepository::TABLE);
        $schemaManager = $connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns(McpPromptTemplateRepository::TABLE);

        return isset($columns[self::COLUMN]);
    }

    private function hasIndex(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(McpPromptTemplateRepository::TABLE);
        $schemaManager = $connection->createSchemaManager();
        $indexes = $schemaManager->listTableIndexes(McpPromptTemplateRepository::TABLE);

        return isset($indexes[self::INDEX]);
    }
}
