<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Tool\Schema;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\PermissionService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

#[McpToolSeverity(ToolSeverity::Read)]
readonly class TableSchemaTool implements McpNonAiToolInterface
{
    public function __construct(
        private TcaSchemaService $tcaSchemaService,
        private PermissionService $permissionService,
    ) {}

    #[McpTool(
        name: 'table_schema',
        description: 'Get the schema of a database table including field types, labels, select options, and constraints.'
            . ' Use this to discover valid field values before creating or updating records.'
            . ' Includes category/MM relations (writableAs=uid_list), file fields (writableAs=file_references),'
            . ' and Content Blocks collections (type=collection, write child table via foreignField).'
            . ' Optional fields (comma-separated names), types (comma-separated TCA types), and compact=true reduce output size.',
    )]
    public function execute(
        string $tableName,
        string $fields = '',
        string $types = '',
        bool $compact = false,
    ): string {
        $this->permissionService->assertCanSelectTable($tableName);

        $missing = $this->tcaSchemaService->missingTableMessage($tableName);
        if ($missing !== null) {
            throw new \InvalidArgumentException($missing, 1790600004);
        }

        $onlyFields = $fields !== '' ? array_values(array_filter(array_map('trim', explode(',', $fields)))) : [];
        $onlyTypes = $types !== '' ? array_values(array_filter(array_map('trim', explode(',', $types)))) : [];
        $schema = $this->tcaSchemaService->getFieldsSchemaFiltered($tableName, $onlyFields, $onlyTypes, $compact);

        if ($schema['fields'] === []) {
            throw new \InvalidArgumentException(
                $this->tcaSchemaService->noReadableFieldsMessage($tableName),
                1790600004,
            );
        }

        return json_encode($schema, JSON_THROW_ON_ERROR);
    }
}
