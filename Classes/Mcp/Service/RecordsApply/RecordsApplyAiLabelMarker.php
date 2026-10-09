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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Service\ApplicableTablesResolver;
use NITSAN\NsT3AF\Api\AiLabelRecorderInterface;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceContextService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Marks the records an MCP write touched as AI-involved, so the AI Label module knows about them.
 *
 * An MCP client is an AI, so a record it creates is AI-generated and a record whose content it
 * changes is AI-modified. Recording source is "mcp". It runs AFTER the batch has been committed:
 * the label is written straight to the record (like every other AI Label origin), and a labelling
 * problem must never undo or fail a write that has already happened.
 *
 * Only tables registered for AI Label fields are marked, and only when the setting
 * mcpMarkWritesAsAi is on (the default). A change that only touches visibility, dates, position or
 * access (hidden, starttime, endtime, fe_group, sorting, colPos, sys_language_uid, editlock) does
 * not change the content, so it does not mark the record: hiding 40 news records must not make
 * them all "AI-modified". A record that is already AI-generated stays AI-generated.
 *
 * In a workspace an update lands on the workspace VERSION of the record, so that is the row to mark;
 * marking the live row would change live data from inside a workspace.
 */
readonly class RecordsApplyAiLabelMarker
{
    public const RECORDING_SOURCE = 'mcp';

    /** Fields whose change alone is not a change of content. */
    private const NON_CONTENT_FIELDS = ['hidden', 'starttime', 'endtime', 'fe_group', 'sorting', 'colPos', 'sys_language_uid', 'editlock'];

    public function __construct(
        private AiLabelRecorderInterface $recorder,
        private ApplicableTablesResolver $applicableTables,
        private AdvancedSettingsService $settings,
        private WorkspaceContextService $workspaceContext,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, list<int>> $created table => uids of the records the call created
     * @param array<string, array<int, list<string>>> $updated table => [uid => names of the fields written]
     * @return int how many records were marked
     */
    public function mark(array $created, array $updated): int
    {
        if (!$this->settings->markWritesAsAi()) {
            return 0;
        }

        $marked = 0;

        foreach ($created as $table => $uids) {
            if (!$this->applicableTables->isApplicable($table)) {
                continue;
            }

            foreach ($uids as $uid) {
                $marked += $this->attempt($table, $uid, fn() => $this->recorder->recordOrigin(
                    $table,
                    $uid,
                    Involvement::AiGenerated,
                    self::RECORDING_SOURCE,
                ));
            }
        }

        foreach ($updated as $table => $records) {
            if (!$this->applicableTables->isApplicable($table)) {
                continue;
            }

            foreach ($records as $uid => $fieldNames) {
                if (array_diff($fieldNames, self::NON_CONTENT_FIELDS) === []) {
                    continue;
                }

                $target = $this->rowToMark($table, $uid);
                if ($this->involvementOf($table, $target) === Involvement::AiGenerated->value) {
                    continue;
                }

                $marked += $this->attempt($table, $target, fn() => $this->recorder->markModified($table, $target, self::RECORDING_SOURCE));
            }
        }

        return $marked;
    }

    private function attempt(string $table, int $uid, \Closure $mark): int
    {
        try {
            $mark();

            return 1;
        } catch (\Throwable $throwable) {
            $this->logger->warning('records_apply: AI Label marking failed', [
                'table' => $table,
                'uid' => $uid,
                'exception' => $throwable,
            ]);

            return 0;
        }
    }

    private function rowToMark(string $table, int $uid): int
    {
        $workspace = $this->workspaceContext->getCurrentWorkspaceId();
        if ($workspace === 0 || !$this->workspaceContext->isTableWorkspaceAware($table)) {
            return $uid;
        }

        $version = BackendUtility::getWorkspaceVersionOfRecord($workspace, $table, $uid, 'uid');

        return is_array($version) ? (int) $version['uid'] : $uid;
    }

    private function involvementOf(string $table, int $uid): string
    {
        try {
            // No default restrictions: they would hide hidden records (new pages are created hidden)
            // and the record would wrongly look unlabelled.
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $value = $queryBuilder
                ->select('tx_nst3af_ailabel_involvement')
                ->from($table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable) {
            return '';
        }

        return is_string($value) ? $value : '';
    }
}
