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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Tool\Redirect;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class RedirectListTool implements McpNonAiToolInterface
{
    private const TABLE = 'sys_redirect';

    /** @var list<string> */
    private const LIST_FIELDS = [
        'uid',
        'pid',
        'source_host',
        'source_path',
        'target',
        'target_statuscode',
        'disabled',
    ];

    public function __construct(private RecordService $recordService) {}

    #[McpTool(
        name: 'redirect_list',
        description: 'List redirect records with pagination and optional filtering.'
            . ' Use sourceHost, sourcePath, target for text search (LIKE).'
            . ' Use disabled (0 or 1) to filter by status. Use pid to filter by page (0 = all pages).'
            . ' Requires cms-redirects extension.',
    )]
    public function execute(
        int $pid = 0,
        int $limit = 20,
        int $offset = 0,
        string $sourceHost = '',
        string $sourcePath = '',
        string $target = '',
        int $disabled = -1,
    ): string {
        if (!ExtensionManagementUtility::isLoaded('redirects')) {
            return json_encode(['error' => 'cms-redirects extension is not installed'], JSON_THROW_ON_ERROR);
        }

        /** @var array<string, array{operator: string, value: string}> $conditions */
        $conditions = [];

        if ($sourceHost !== '') {
            $conditions['source_host'] = ['operator' => 'like', 'value' => $sourceHost];
        }
        if ($sourcePath !== '') {
            $conditions['source_path'] = ['operator' => 'like', 'value' => $sourcePath];
        }
        if ($target !== '') {
            $conditions['target'] = ['operator' => 'like', 'value' => $target];
        }
        if ($disabled >= 0) {
            $conditions['disabled'] = ['operator' => 'eq', 'value' => (string) $disabled];
        }

        $result = $this->recordService->search(
            self::TABLE,
            $conditions,
            $limit,
            $offset,
            self::LIST_FIELDS,
            $pid > 0 ? $pid : null,
            'uid',
            'DESC',
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
