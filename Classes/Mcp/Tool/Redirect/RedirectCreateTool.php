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

namespace NITSAN\NsT3AF\Mcp\Tool\Redirect;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\McpRecordPlanService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[McpToolSeverity(ToolSeverity::Write)]
readonly class RedirectCreateTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    private const TABLE = 'sys_redirect';

    /** @var list<int> */
    public const ALLOWED_STATUS_CODES = [301, 302, 303, 307, 308];

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

    public function __construct(
        private DataHandlerService $dataHandlerService,
        private McpRecordPlanService $recordPlanService,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $data = [
            'pid' => (int) ($arguments['pid'] ?? 0),
            'source_host' => (string) ($arguments['sourceHost'] ?? ''),
            'source_path' => (string) ($arguments['sourcePath'] ?? ''),
            'target' => (string) ($arguments['target'] ?? ''),
            'target_statuscode' => (int) ($arguments['targetStatuscode'] ?? 301),
        ];

        $fieldsRaw = (string) ($arguments['fields'] ?? '');
        if ($fieldsRaw !== '') {
            /** @var array<string, mixed> $extra */
            $extra = json_decode($fieldsRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($extra)) {
                throw new \InvalidArgumentException('fields must be a JSON object.');
            }
            $data = array_merge($extra, $data);
        }

        $refused = self::refusedReason(
            (string) $data['target'],
            (int) $data['target_statuscode'],
            $this->existingRedirectUid((string) $data['source_host'], (string) $data['source_path']),
        );
        if ($refused !== null) {
            throw new \InvalidArgumentException($refused);
        }

        return $this->recordPlanService->planCreate(
            self::TABLE,
            $data,
            'redirect_create',
            self::WRITABLE_FIELDS,
        );
    }

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

        $refused = self::refusedReason(
            (string) ($filteredData['target'] ?? ''),
            (int) ($filteredData['target_statuscode'] ?? 301),
            $this->existingRedirectUid(
                (string) ($filteredData['source_host'] ?? ''),
                (string) ($filteredData['source_path'] ?? ''),
            ),
        );
        if ($refused !== null) {
            return json_encode(['error' => $refused, 'ignoredFields' => $ignoredFields], JSON_THROW_ON_ERROR);
        }

        $uid = $this->dataHandlerService->createRecord(self::TABLE, $pid, $filteredData);

        return json_encode(['uid' => $uid, 'ignoredFields' => $ignoredFields], JSON_THROW_ON_ERROR);
    }

    public static function refusedReason(string $target, int $status, int $existingUid = 0): ?string
    {
        if (!self::isAllowedTarget($target)) {
            return 'The redirect target must be a t3 link, a site path, or an http or https URL.';
        }
        if (!in_array($status, self::ALLOWED_STATUS_CODES, true)) {
            return 'The redirect status must be 301, 302, 303, 307, or 308.';
        }
        if ($existingUid > 0) {
            return 'A redirect with this host and path already exists.';
        }

        return null;
    }

    public static function isAllowedTarget(string $target): bool
    {
        $target = trim($target);
        if ($target === '') {
            return false;
        }
        if (str_starts_with($target, 't3://') || str_starts_with($target, '/')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    private function existingRedirectUid(string $sourceHost, string $sourcePath): int
    {
        $sourceHost = trim($sourceHost);
        $sourcePath = trim($sourcePath);
        if ($sourceHost === '' || $sourcePath === '') {
            return 0;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $uid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter($sourceHost)),
                $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($uid) ? (int) $uid : 0;
    }
}
