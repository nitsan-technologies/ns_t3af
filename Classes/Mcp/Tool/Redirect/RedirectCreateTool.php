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
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

readonly class RedirectCreateTool implements McpNonAiToolInterface
{
    private const TABLE = 'sys_redirect';

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'source_host',
        'source_path',
        'is_regexp',
        'target',
        'target_statuscode',
        'force_https',
        'keep_query_parameters',
        'respect_query_parameters',
        'protected',
        'disabled',
        'description',
        'starttime',
        'endtime',
    ];

    public function __construct(private DataHandlerService $dataHandlerService) {}

    #[McpTool(
        name: 'redirect_create',
        description: 'Create a new redirect record. Required: sourceHost (domain or "*"), sourcePath, target (URL or t3:// link).'
            . ' Optional: pid (default 0), targetStatuscode (default 301),'
            . ' fields as JSON for additional options: is_regexp, force_https, keep_query_parameters,'
            . ' respect_query_parameters, protected, disabled, description, starttime, endtime.'
            . ' Requires cms-redirects extension.',
    )]
    public function execute(
        string $sourceHost,
        string $sourcePath,
        string $target,
        int $pid = 0,
        int $targetStatuscode = 301,
        string $fields = '',
    ): string {
        if (!ExtensionManagementUtility::isLoaded('redirects')) {
            return json_encode(['error' => 'cms-redirects extension is not installed'], JSON_THROW_ON_ERROR);
        }

        $data = [
            'source_host' => $sourceHost,
            'source_path' => $sourcePath,
            'target' => $target,
            'target_statuscode' => $targetStatuscode,
        ];

        if ($fields !== '') {
            /** @var array<string, mixed> $extra */
            $extra = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);
            $data = array_merge($extra, $data);
        }

        $filteredData = array_intersect_key($data, array_flip(self::WRITABLE_FIELDS));
        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

        if ($filteredData === []) {
            return json_encode(['error' => 'No valid fields provided', 'ignoredFields' => $ignoredFields], JSON_THROW_ON_ERROR);
        }

        $uid = $this->dataHandlerService->createRecord(self::TABLE, $pid, $filteredData);

        return json_encode(['uid' => $uid, 'ignoredFields' => $ignoredFields], JSON_THROW_ON_ERROR);
    }
}
