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

use const JSON_THROW_ON_ERROR;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceContextService;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceListService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Model\CorrelationId;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Applies many record changes as ONE DataHandler run inside ONE database transaction.
 *
 * Order of events:
 *  1. expand the bulk shorthand, then preflight: refuse everything that can be known to fail, before touching the database,
 *  2. append: let new records land at the end of their page, in the order sent,
 *  3. begin a transaction, run the datamap, then the cmdmap with the same batch id,
 *  4. any DataHandler error, thrown exception or dry run rolls EVERYTHING back; otherwise commit (a requestId's stored answer is written just before it),
 *  5. mark the written records as AI-involved in the AI Label module (after the commit, never inside it),
 *  6. write the audit entry (after the transaction, so a rollback cannot take it with it).
 *
 * The batch id is the DataHandler correlation scope: every sys_history row the run writes carries it.
 *
 * A dry run is the REAL run followed by a rollback, so permission, validation and hook failures show
 * up in it. Rows, sys_history and sys_log written inside the transaction are rolled back. Anything a
 * hook does outside the database (a queue message, an HTTP call) is not, and caches kept outside the
 * database are not either.
 */
readonly class RecordsApplyService
{
    private const MAX_ERRORS = 20;

    public function __construct(
        private RecordsApplyBulkExpander $bulkExpander,
        private RecordsApplyPreflight $preflight,
        private RecordAppendOrderer $orderer,
        private RecordsApplyAudit $audit,
        private RecordsApplyAiLabelMarker $aiLabelMarker,
        private RecordsApplyIdempotency $idempotency,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
        private ?WorkspaceContextService $workspaceContext = null,
        private ?WorkspaceListService $workspaceList = null,
    ) {}

    /**
     * @param array<mixed> $datamap DataHandler datamap: table => [uid|NEW id => fields]
     * @param array<mixed> $cmdmap DataHandler cmdmap: table => [uid => [command => value]]
     * @param array<mixed> $bulk shorthand entries, expanded into the two maps above before anything is checked
     * @param string $tool name of the calling MCP tool, for the audit entry (records_apply, or write_table which runs on this engine)
     * @param string $batchId fixed batch id, for callers that must find the batch again by a known name (records_undo); empty = a random one
     * @param string $requestId client-chosen id that makes a retry safe: the same id with the same payload is applied once and answered from the stored result. Ignored for dry runs.
     * @throws RecordsApplyValidationException when the request is refused before anything is written
     * @throws ToolCallException when DataHandler refuses or fails; everything is rolled back
     */
    public function apply(array $datamap, array $cmdmap, bool $dryRun, bool $strict, bool $append, array $bulk = [], string $tool = 'records_apply', string $requestId = '', string $batchId = ''): RecordsApplyResult
    {
        $batchId = $batchId !== '' ? $batchId : 'ra-' . bin2hex(random_bytes(10));

        $payloadHash = '';
        if ($requestId !== '') {
            RecordsApplyIdempotency::assertValidRequestId($requestId);
        }

        // Dry runs never take, store or replay a request id.
        $useRequestId = $requestId !== '' && !$dryRun;
        if ($useRequestId) {
            $payloadHash = RecordsApplyIdempotency::payloadHash($datamap, $cmdmap, $bulk, $strict, $append);
            $stored = $this->idempotency->lookup($tool, $requestId, $payloadHash);
            if ($stored !== null) {
                return $this->replayed($tool, $stored);
            }
        }

        try {
            [$datamap, $cmdmap] = $this->bulkExpander->expand($bulk, $datamap, $cmdmap);
            $checked = $this->preflight->check($datamap, $cmdmap, $strict);
        } catch (RecordsApplyValidationException $exception) {
            $this->audit->log($tool, $batchId, $dryRun, false, 'validation', [], [], $exception->getMessage());

            throw $exception;
        }

        $datamap = $checked['datamap'];
        $cmdmap = $checked['cmdmap'];
        $operations = self::operations($datamap, $cmdmap);
        $fieldNames = self::fieldNames($datamap);

        if ($append) {
            $datamap = $this->orderer->apply($datamap);
        }

        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if ($connection->isTransactionActive()) {
            $message = 'records_apply cannot run inside an open database transaction, because it could not roll back on its own. Nothing was written.';
            $this->audit->log($tool, $batchId, $dryRun, false, 'transaction', $operations, $fieldNames, $message);

            throw new ToolCallException($message, 1790500002);
        }

        $lock = null;
        if ($useRequestId) {
            $acquired = $this->idempotency->acquire($tool, $requestId, $payloadHash);
            if ($acquired instanceof RecordsApplyResult) {
                return $this->replayed($tool, $acquired);
            }

            $lock = $acquired;
        }

        try {
            $connection->beginTransaction();
        } catch (\Throwable $throwable) {
            $this->release($lock, $batchId);

            throw $throwable;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        try {
            $dataHandler->start($datamap, $cmdmap);
            $dataHandler->setCorrelationId(CorrelationId::forScope($batchId));
            $dataHandler->process_datamap();
            if ($dataHandler->errorLog === []) {
                $dataHandler->process_cmdmap();
            }
        } catch (\Throwable $throwable) {
            $this->rollBack($connection);
            $this->release($lock, $batchId);
            $this->logger->error('records_apply: DataHandler threw', ['exception' => $throwable, 'batchId' => $batchId]);

            $summary = sprintf('%s (code %d)', $throwable::class, (int) $throwable->getCode());
            $this->audit->log($tool, $batchId, $dryRun, false, 'datahandler', $operations, $fieldNames, $summary);

            $unbroken = self::unbrokenRunHint($datamap);
            if ($unbroken !== null) {
                throw new ToolCallException(
                    sprintf(
                        'The batch was rolled back and nothing was written: %s TYPO3 scans rich text for links and e-mail addresses and cannot handle one extremely long run of characters without spaces or line breaks. Add spaces or line breaks to the text and try again (batch %s).',
                        $unbroken,
                        $batchId,
                    ),
                    1790500003,
                    $throwable,
                );
            }

            throw new ToolCallException(
                sprintf(
                    'DataHandler threw %s while applying the batch. The whole call was rolled back and nothing was written. The details are in the TYPO3 log (batch %s).',
                    $summary,
                    $batchId,
                ),
                1790500003,
                $throwable,
            );
        }

        $errors = self::errors($dataHandler->errorLog);
        if ($errors !== []) {
            $this->rollBack($connection);
            $this->release($lock, $batchId);
            $this->audit->log($tool, $batchId, $dryRun, false, 'datahandler', $operations, $fieldNames, implode(' | ', $errors));

            throw new ToolCallException(
                json_encode([
                    'ok' => false,
                    'written' => false,
                    'stage' => 'datahandler',
                    'batchId' => $batchId,
                    'note' => 'DataHandler refused part of the batch. The whole call was rolled back and nothing was written.',
                    'errors' => $errors,
                ], JSON_THROW_ON_ERROR),
                1790500004,
            );
        }

        $created = [];
        foreach ($dataHandler->substNEWwithIDs as $newId => $uid) {
            if (str_starts_with((string) $newId, 'NEW')) {
                $created[(string) $newId] = (int) $uid;
            }
        }

        $copied = [];
        foreach ($dataHandler->copyMappingArray as $table => $mapping) {
            foreach ($mapping as $sourceUid => $copyUid) {
                $copied[(string) $table][(int) $sourceUid] = (int) $copyUid;
            }
        }

        $result = new RecordsApplyResult(
            $batchId,
            $dryRun,
            !$dryRun,
            $created,
            $copied,
            $operations,
            $checked['ignored'],
        );

        // Say where the change went. It is stored with a requestId's answer, so a replay names the same workspace.
        $workspaceId = $this->workspaceContext?->getCurrentWorkspaceId();
        if ($workspaceId !== null) {
            $result = $result->withWorkspace($workspaceId, $this->workspaceTitle($workspaceId));
        }

        if ($dryRun) {
            $this->rollBack($connection);
        } else {
            try {
                // The stored answer is written inside the transaction: the data and the row exist together, or neither does.
                if ($lock !== null) {
                    $this->idempotency->complete($lock, $result->toArray());
                }

                $connection->commit();
            } catch (\Throwable $throwable) {
                $this->rollBack($connection);
                $this->release($lock, $batchId);
                $this->logger->error('records_apply: commit failed', ['exception' => $throwable, 'batchId' => $batchId]);
                $summary = sprintf('%s (code %d)', $throwable::class, (int) $throwable->getCode());
                $this->audit->log($tool, $batchId, $dryRun, false, 'commit', $operations, $fieldNames, $summary);

                throw new ToolCallException(
                    sprintf('The database refused to commit the batch (%s). Nothing was written (batch %s).', $summary, $batchId),
                    1790500005,
                    $throwable,
                );
            }
        }

        $aiLabelled = 0;
        if (!$dryRun) {
            [$createdRecords, $updatedRecords] = self::writtenRecords($datamap, $created);

            try {
                $aiLabelled = $this->aiLabelMarker->mark($createdRecords, $updatedRecords);
            } catch (\Throwable $throwable) {
                // The batch is committed; a labelling problem must not turn it into a failure.
                $this->logger->warning('records_apply: AI Label marking failed', ['exception' => $throwable, 'batchId' => $batchId]);
            }
        }

        $this->audit->log($tool, $batchId, $dryRun, true, $dryRun ? 'dry-run' : 'applied', $operations, $fieldNames);

        return $result->withAiLabelled($aiLabelled);
    }

    private function workspaceTitle(int $workspaceId): string
    {
        try {
            return $this->workspaceList?->resolveTitle($workspaceId) ?? ($workspaceId === 0 ? 'Live' : 'Workspace #' . $workspaceId);
        } catch (\Throwable) {
            return $workspaceId === 0 ? 'Live' : 'Workspace #' . $workspaceId;
        }
    }

    /** A retry of a finished call: nothing is written, the audit entry says so. */
    private function replayed(string $tool, RecordsApplyResult $result): RecordsApplyResult
    {
        $this->audit->log($tool, $result->batchId, false, true, 'replayed', $result->operations, []);

        return $result;
    }

    /** The call failed and wrote nothing: free its request id so the client can retry. */
    private function release(?RecordsApplyIdempotencyLock $lock, string $batchId): void
    {
        if ($lock === null) {
            return;
        }

        try {
            $this->idempotency->release($lock);
        } catch (\Throwable $throwable) {
            // A stale pending row is taken over by the next retry after the timeout, so this is not fatal.
            $this->logger->warning('records_apply: could not release the requestId', ['exception' => $throwable, 'batchId' => $batchId]);
        }
    }

    private function rollBack(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, array<int, array<string, int>>> $cmdmap
     * @return array<string, array<string, int>>
     */
    private static function operations(array $datamap, array $cmdmap): array
    {
        $operations = [];
        foreach ($datamap as $table => $records) {
            foreach (array_keys($records) as $id) {
                $kind = str_starts_with((string) $id, 'NEW') ? 'create' : 'update';
                $operations[$table][$kind] = ($operations[$table][$kind] ?? 0) + 1;
            }
        }

        foreach ($cmdmap as $table => $records) {
            foreach ($records as $commands) {
                foreach (array_keys($commands) as $command) {
                    $operations[$table][$command] = ($operations[$table][$command] ?? 0) + 1;
                }
            }
        }

        return $operations;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @return array<string, list<string>>
     */
    private static function fieldNames(array $datamap): array
    {
        $names = [];
        foreach ($datamap as $table => $records) {
            foreach ($records as $fields) {
                foreach (array_keys($fields) as $field) {
                    if ($field !== 'pid') {
                        $names[$table][(string) $field] = true;
                    }
                }
            }
        }

        return array_map(static fn(array $set): array => array_keys($set), $names);
    }

    /**
     * Which records the call created and which it updated, by table, for the AI Label marking.
     *
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, int> $created NEW id => uid
     * @return array{0: array<string, list<int>>, 1: array<string, array<int, list<string>>>}
     */
    private static function writtenRecords(array $datamap, array $created): array
    {
        $createdRecords = [];
        $updatedRecords = [];

        foreach ($datamap as $table => $records) {
            foreach ($records as $id => $fields) {
                $id = (string) $id;
                if (str_starts_with($id, 'NEW')) {
                    if (isset($created[$id])) {
                        $createdRecords[$table][] = $created[$id];
                    }

                    continue;
                }

                $fieldNames = array_values(array_map('strval', array_keys(array_diff_key($fields, ['pid' => true]))));
                $updatedRecords[$table][(int) $id] = $fieldNames;
            }
        }

        return [$createdRecords, $updatedRecords];
    }

    /**
     * DataHandler's own refusals ("Attempt to modify record … without permission", an invalid value),
     * which are editor-facing messages. Cut and stripped of control characters before they are relayed.
     *
     * @param array<mixed> $errorLog
     * @return list<string>
     */
    private static function errors(array $errorLog): array
    {
        $errors = [];
        foreach ($errorLog as $entry) {
            if (count($errors) >= self::MAX_ERRORS) {
                $errors[] = '… more errors omitted.';
                break;
            }

            $text = is_string($entry) ? $entry : (string) json_encode($entry);
            $errors[] = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $text), 0, 300);
        }

        return $errors;
    }

    /**
     * Finds a text value with one very long run of characters without whitespace.
     *
     * @param array<mixed> $datamap
     */
    private static function unbrokenRunHint(array $datamap): ?string
    {
        foreach ($datamap as $table => $rows) {
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $id => $fields) {
                if (!is_array($fields)) {
                    continue;
                }

                foreach ($fields as $field => $value) {
                    if (!is_string($value) || strlen($value) <= 262144) {
                        continue;
                    }

                    foreach (preg_split('/\\s+/', $value) ?: [] as $word) {
                        if (strlen($word) > 262144) {
                            return sprintf('The field "%s" of %s %s contains a run of more than 256 KB without any space.', $field, $table, $id);
                        }
                    }
                }
            }
        }

        return null;
    }
}
