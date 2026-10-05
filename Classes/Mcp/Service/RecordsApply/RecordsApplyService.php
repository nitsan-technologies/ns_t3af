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
 *  1. preflight: refuse everything that can be known to fail, before touching the database,
 *  2. append: let new records land at the end of their page, in the order sent,
 *  3. begin a transaction, run the datamap, then the cmdmap with the same batch id,
 *  4. any DataHandler error, thrown exception or dry run rolls EVERYTHING back; otherwise commit,
 *  5. write the audit entry (after the transaction, so a rollback cannot take it with it).
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
        private RecordsApplyPreflight $preflight,
        private RecordAppendOrderer $orderer,
        private RecordsApplyAudit $audit,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<mixed> $datamap DataHandler datamap: table => [uid|NEW id => fields]
     * @param array<mixed> $cmdmap DataHandler cmdmap: table => [uid => [command => value]]
     * @throws RecordsApplyValidationException when the request is refused before anything is written
     * @throws ToolCallException when DataHandler refuses or fails; everything is rolled back
     */
    public function apply(array $datamap, array $cmdmap, bool $dryRun, bool $strict, bool $append): RecordsApplyResult
    {
        $batchId = 'ra-' . bin2hex(random_bytes(10));

        try {
            $checked = $this->preflight->check($datamap, $cmdmap, $strict);
        } catch (RecordsApplyValidationException $exception) {
            $this->audit->log($batchId, $dryRun, false, 'validation', [], [], $exception->getMessage());

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
            $this->audit->log($batchId, $dryRun, false, 'transaction', $operations, $fieldNames, $message);

            throw new ToolCallException($message, 1790500002);
        }

        $connection->beginTransaction();
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
            $this->logger->error('records_apply: DataHandler threw', ['exception' => $throwable, 'batchId' => $batchId]);

            $summary = sprintf('%s (code %d)', $throwable::class, (int) $throwable->getCode());
            $this->audit->log($batchId, $dryRun, false, 'datahandler', $operations, $fieldNames, $summary);

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
            $this->audit->log($batchId, $dryRun, false, 'datahandler', $operations, $fieldNames, implode(' | ', $errors));

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

        if ($dryRun) {
            $this->rollBack($connection);
        } else {
            try {
                $connection->commit();
            } catch (\Throwable $throwable) {
                $this->rollBack($connection);
                $this->logger->error('records_apply: commit failed', ['exception' => $throwable, 'batchId' => $batchId]);
                $summary = sprintf('%s (code %d)', $throwable::class, (int) $throwable->getCode());
                $this->audit->log($batchId, $dryRun, false, 'commit', $operations, $fieldNames, $summary);

                throw new ToolCallException(
                    sprintf('The database refused to commit the batch (%s). Nothing was written (batch %s).', $summary, $batchId),
                    1790500005,
                    $throwable,
                );
            }
        }

        $this->audit->log($batchId, $dryRun, true, $dryRun ? 'dry-run' : 'applied', $operations, $fieldNames);

        return new RecordsApplyResult(
            $batchId,
            $dryRun,
            !$dryRun,
            $created,
            $copied,
            $operations,
            $checked['ignored'],
        );
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
}
