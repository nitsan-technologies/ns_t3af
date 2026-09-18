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

namespace NITSAN\NsT3AF\Mcp\Service;

use const JSON_THROW_ON_ERROR;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class DataHandlerService
{
    public function __construct(
        private SiteFinder $siteFinder,
        private RecordService $recordService,
    ) {}

    /**
     * @param array<string, mixed> $fields
     */
    public function createRecord(string $table, int $pid, array $fields): int
    {
        $newId = 'NEW' . bin2hex(random_bytes(8));
        $fields['pid'] = $pid;

        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($pid) : null;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => [$newId => $fields]], []);
            $dataHandler->process_datamap();

            $this->checkErrors($dataHandler);

            /** @var int|string|null $uid */
            $uid = $dataHandler->substNEWwithIDs[$newId] ?? null;
            if ($uid === null) {
                throw new \RuntimeException('Failed to create record: no uid returned', 1712000020);
            }

            return (int) $uid;
        } finally {
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }
    }

    /** @param array<string, mixed> $fields */
    public function updateRecord(string $table, int $uid, array $fields): void
    {
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($uid) : null;

        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => [$uid => $fields]], []);
            $dataHandler->process_datamap();

            $this->checkErrors($dataHandler);
        } finally {
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }
    }

    public function deleteRecord(string $table, int $uid): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        $this->checkErrors($dataHandler);
    }

    /**
     * Move a record. $target follows the DataHandler convention:
     * positive => destination page id (top), negative => -(uid) of sibling to place after.
     */
    public function moveRecord(string $table, int $uid, int $target): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['move' => $target]]]);
        $dataHandler->process_cmdmap();

        $this->checkErrors($dataHandler);
    }

    /**
     * Copy a record to a new position.
     *
     * @param int $target Positive = destination pid, negative = -(uid) of record to copy after
     * @param int $copyTreeDepth For pages: depth of subpages to include (0 = page only, 99 = all)
     */
    public function copyRecord(string $table, int $uid, int $target, int $copyTreeDepth = 0): int
    {
        $originalRequest = $table === 'pages' ? $this->ensureSiteContext($uid) : null;
        $previousCopyLevels = null;

        try {
            if ($table === 'pages' && $copyTreeDepth > 0 && isset($GLOBALS['BE_USER'])) {
                $beUser = $GLOBALS['BE_USER'];
                if ($beUser instanceof BackendUserAuthentication) {
                    $previousCopyLevels = $beUser->uc['copyLevels'] ?? 0;
                    $beUser->uc['copyLevels'] = $copyTreeDepth;
                }
            }

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], [$table => [$uid => ['copy' => $target]]]);
            $dataHandler->process_cmdmap();

            $this->checkErrors($dataHandler);

            $newUid = $dataHandler->copyMappingArray[$table][$uid] ?? null;
            if (!is_int($newUid) && !is_string($newUid)) {
                throw new \RuntimeException('Copy command did not return a new record uid', 1712000040);
            }

            return (int) $newUid;
        } finally {
            if ($previousCopyLevels !== null) {
                $beUser = $GLOBALS['BE_USER'] ?? null;
                if ($beUser instanceof BackendUserAuthentication) {
                    $beUser->uc['copyLevels'] = $previousCopyLevels;
                }
            }
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
        }
    }

    /** @param list<int> $uids */
    public function deleteRecords(string $table, array $uids): void
    {
        $cmdmap = [];
        foreach ($uids as $uid) {
            $cmdmap[$uid] = ['delete' => 1];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => $cmdmap]);
        $dataHandler->process_cmdmap();

        $this->checkErrors($dataHandler);
    }

    /**
     * @param list<int> $uids
     * @param array<string, mixed> $fields
     */
    public function updateRecords(string $table, array $uids, array $fields): void
    {
        $datamap = [];
        foreach ($uids as $uid) {
            $datamap[$uid] = $fields;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => $datamap], []);
        $dataHandler->process_datamap();

        $this->checkErrors($dataHandler);
    }

    /** @param list<int> $uids */
    public function moveRecords(string $table, array $uids, int $target): void
    {
        $cmdmap = [];
        foreach ($uids as $uid) {
            $cmdmap[$uid] = ['move' => $target];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => $cmdmap]);
        $dataHandler->process_cmdmap();

        $this->checkErrors($dataHandler);
    }

    /**
     * Attach sys_file records to a TCA file field via DataHandler.
     *
     * @param list<int> $fileUids sys_file UIDs to attach
     * @return list<int> UIDs of the created sys_file_reference records
     */
    public function createFileReferences(string $table, int $recordUid, string $fieldName, array $fileUids): array
    {
        if ($fileUids === []) {
            throw new \RuntimeException('No file UIDs provided for file reference creation.', 1712002100);
        }

        $references = [];
        foreach ($fileUids as $fileUid) {
            $references[] = ['uid_local' => $fileUid];
        }

        return $this->writeFileFieldReferences($table, $recordUid, $fieldName, $references, replaceExisting: false);
    }

    /**
     * Replace (or clear) all sys_file_reference rows for a TCA file field.
     *
     * Empty $references clears existing attachments. Non-empty lists delete
     * existing refs first, then create NEW ones in array order (sorting_foreign).
     *
     * @param list<array{uid_local: int, alternative?: string, title?: string, description?: string, link?: string, crop?: string}> $references
     * @return list<int> reference uids
     */
    public function replaceFileFieldReferences(string $table, int $recordUid, string $fieldName, array $references): array
    {
        return $this->writeFileFieldReferences($table, $recordUid, $fieldName, $references, replaceExisting: true);
    }

    /**
     * @param list<array{uid_local: int, alternative?: string, title?: string, description?: string, link?: string, crop?: string}> $references
     * @return list<int>
     */
    private function writeFileFieldReferences(
        string $table,
        int $recordUid,
        string $fieldName,
        array $references,
        bool $replaceExisting,
    ): array {
        if ($replaceExisting) {
            $existingUids = [];
            foreach ($this->recordService->findFileReferences($table, $recordUid, $fieldName) as $row) {
                $uid = (int) ($row['uid'] ?? 0);
                if ($uid > 0) {
                    $existingUids[] = $uid;
                }
            }
            if ($existingUids !== []) {
                $this->deleteRecords('sys_file_reference', $existingUids);
            }
        }

        if ($references === []) {
            if ($replaceExisting) {
                $this->updateRecord($table, $recordUid, [$fieldName => '']);
            }

            return [];
        }

        $newIds = [];
        $datamap = [];
        $metaKeys = ['alternative', 'title', 'description', 'link', 'crop'];

        // Keep existing file references when appending so DataHandler updates the
        // parent counter (og_image etc.) instead of orphaning prior relations.
        $parentValueParts = [];
        if (!$replaceExisting) {
            foreach ($this->recordService->findFileReferences($table, $recordUid, $fieldName) as $row) {
                $existingUid = (int) ($row['uid'] ?? 0);
                if ($existingUid > 0) {
                    $parentValueParts[] = (string) $existingUid;
                }
            }
        }

        foreach ($references as $index => $reference) {
            $fileUid = (int) ($reference['uid_local'] ?? 0);
            if ($fileUid <= 0) {
                throw new \RuntimeException(
                    'Invalid uid_local in file field "' . $fieldName . '" (index ' . $index . ').',
                    1712002101,
                );
            }

            $newId = 'NEW_ref_' . bin2hex(random_bytes(4));
            $newIds[] = $newId;
            $parentValueParts[] = $newId;

            $row = [
                'uid_local' => $fileUid,
                'uid_foreign' => $recordUid,
                'tablenames' => $table,
                'fieldname' => $fieldName,
                'sorting_foreign' => count($parentValueParts),
                'pid' => 0,
            ];

            foreach ($metaKeys as $metaKey) {
                if (!array_key_exists($metaKey, $reference)) {
                    continue;
                }
                $metaValue = $reference[$metaKey];
                if (is_string($metaValue)) {
                    $row[$metaKey] = $metaValue;
                }
            }

            $datamap['sys_file_reference'][$newId] = $row;
        }

        $datamap[$table][$recordUid] = [
            $fieldName => implode(',', $parentValueParts),
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();

        $this->checkErrors($dataHandler);

        $referenceUids = [];
        foreach ($newIds as $newId) {
            /** @var int|string|null $uid */
            $uid = $dataHandler->substNEWwithIDs[$newId] ?? null;
            if ($uid !== null) {
                $referenceUids[] = (int) $uid;
            }
        }

        // Ensure parent FAL counter column matches live references (SEO/og_image).
        $this->syncFileFieldCounter($table, $recordUid, $fieldName);

        return $referenceUids;
    }

    /**
     * Parent FAL columns store a relation count. DataHandler sometimes leaves it
     * at 0 even though sys_file_reference rows exist (breaks SEO og:image). Sync
     * the integer directly without re-processing IRRE relations.
     */
    private function syncFileFieldCounter(string $table, int $recordUid, string $fieldName): void
    {
        $count = 0;
        foreach ($this->recordService->findFileReferences($table, $recordUid, $fieldName) as $row) {
            if ((int) ($row['uid'] ?? 0) > 0) {
                ++$count;
            }
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table)
            ->update($table, [$fieldName => $count], ['uid' => $recordUid]);
    }

    private function ensureSiteContext(int $pageId): ?ServerRequestInterface
    {
        if (!isset($GLOBALS['TYPO3_REQUEST'])) {
            return null;
        }

        /** @var ServerRequestInterface $originalRequest */
        $originalRequest = $GLOBALS['TYPO3_REQUEST'];

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $GLOBALS['TYPO3_REQUEST'] = $originalRequest->withAttribute('site', $site);
        } catch (SiteNotFoundException) {
            // No site found for this page — leave request unchanged
        }

        return $originalRequest;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    public function processCommand(array $cmdmap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap);
        $dataHandler->process_cmdmap();

        $this->checkErrors($dataHandler);
    }

    private function checkErrors(DataHandler $dataHandler): void
    {
        $errorLog = $dataHandler->errorLog;
        if ($errorLog !== []) {
            throw new \RuntimeException(
                'DataHandler errors: ' . implode('; ', array_map(
                    static fn(mixed $e): string => is_string($e) ? $e : json_encode($e, JSON_THROW_ON_ERROR),
                    $errorLog,
                )),
                1712000021,
            );
        }
    }
}
