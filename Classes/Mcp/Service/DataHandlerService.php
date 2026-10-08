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

use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
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

        $hadRequest = isset($GLOBALS['TYPO3_REQUEST']);
        $originalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;

        try {
            if ($table === 'pages') {
                if ($pid < 0) {
                    $liveAnchor = $this->resolveLivePageUid(abs($pid));
                    $pid = -$liveAnchor;
                    $fields['pid'] = $pid;
                    $this->attachSiteAttribute($liveAnchor);
                } else {
                    $pid = $this->resolveLivePageUid($pid);
                    $fields['pid'] = $pid;
                    $this->attachSiteAttribute($pid);
                }
            }

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
            if ($table === 'pages') {
                $this->restoreRequest($hadRequest, $originalRequest);
            }
        }
    }

    /** @param array<string, mixed> $fields */
    public function updateRecord(string $table, int $uid, array $fields): void
    {
        $hadRequest = isset($GLOBALS['TYPO3_REQUEST']);
        $originalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;

        try {
            if ($table === 'pages') {
                // DataHandler page writes must use the live uid — workspace version
                // uids break RootlineUtility / SiteFinder ("Could not fetch page data").
                $uid = $this->resolveLivePageUid($uid);
                $this->attachSiteAttribute($uid);
            }

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([$table => [$uid => $fields]], []);
            $dataHandler->process_datamap();

            $this->checkErrors($dataHandler);
        } finally {
            if ($table === 'pages') {
                $this->restoreRequest($hadRequest, $originalRequest);
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
        $hadRequest = isset($GLOBALS['TYPO3_REQUEST']);
        $originalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $previousCopyLevels = null;

        try {
            if ($table === 'pages') {
                $uid = $this->resolveLivePageUid($uid);
                $this->attachSiteAttribute($uid);
            }

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
            if ($table === 'pages') {
                $this->restoreRequest($hadRequest, $originalRequest);
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
     * Attach sys_file records to a TCA file field via DataHandler.
     *
     * @param list<int> $fileUids sys_file UIDs to attach
     * @param int $pid page of the record (references are stored there)
     * @return list<int> UIDs of the created sys_file_reference records
     */
    public function createFileReferences(string $table, int $recordUid, string $fieldName, array $fileUids, int $pid = 0): array
    {
        if ($fileUids === []) {
            throw new \RuntimeException('No file UIDs provided for file reference creation.', 1712002100);
        }

        $references = [];
        foreach ($fileUids as $fileUid) {
            $references[] = ['uid_local' => $fileUid];
        }

        return $this->writeFileFieldReferences($table, $recordUid, $fieldName, $references, replaceExisting: false, pid: $pid);
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
        int $pid = 0,
    ): array {
        // Same visibility as BackendUtility / DCE AfterSaveHook: deleted rows are invisible.
        $parentRecord = BackendUtility::getRecord($table, $recordUid, 'uid,pid');
        if ($parentRecord === null) {
            throw new \RuntimeException(sprintf(
                'Cannot attach files: %s uid %d was not found (missing or deleted).',
                $table,
                $recordUid,
            ), 1712002102);
        }
        // References live on the page of their record.
        $pid = $pid > 0 ? $pid : max(0, (int) ($parentRecord['pid'] ?? 0));

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

            // Placeholder must not contain "_": DataHandler::processRemapStack() treats
            // underscored child ids as "<table>_<uid>" and fails to resolve them — the
            // reference is then silently dropped from the parent field on v13/v14.
            // Same pattern as createRecord().
            $newId = 'NEW' . bin2hex(random_bytes(8));
            $newIds[] = $newId;
            $parentValueParts[] = $newId;

            $row = [
                'uid_local' => $fileUid,
                'uid_foreign' => $recordUid,
                'tablenames' => $table,
                'fieldname' => $fieldName,
                'sorting_foreign' => count($parentValueParts),
                'pid' => $pid,
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

    /**
     * DataHandler page operations must use the live uid. Workspace version uids break
     * RootlineUtility / SiteFinder ("Could not fetch page data for uid …").
     */
    private function resolveLivePageUid(int $pageId): int
    {
        if ($pageId <= 0) {
            return $pageId;
        }

        $record = BackendUtility::getRecord('pages', $pageId, 'uid,t3ver_oid');
        if (!is_array($record)) {
            return $pageId;
        }

        $oid = (int) ($record['t3ver_oid'] ?? 0);

        return $oid > 0 ? $oid : (int) ($record['uid'] ?? $pageId);
    }

    private function attachSiteAttribute(int $livePageId): void
    {
        if ($livePageId <= 0) {
            return;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($livePageId);
        } catch (SiteNotFoundException) {
            return;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('site', $site);

            return;
        }

        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest((string) $site->getBase()))
            ->withAttribute('site', $site)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function restoreRequest(bool $hadRequest, mixed $originalRequest): void
    {
        if ($hadRequest && $originalRequest instanceof ServerRequestInterface) {
            $GLOBALS['TYPO3_REQUEST'] = $originalRequest;

            return;
        }

        if (!$hadRequest) {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }

    /**
     * Apply only the kept fields from a tool plan (R28: server-side filtering).
     *
     * @param list<string> $keptFieldKeys
     * @return array{
     *     correlationId: string,
     *     action: string,
     *     appliedFieldKeys: list<string>,
     *     affected: list<array{table: string, uid: int}>
     * }
     */
    public function applyFilteredPlan(ToolPlan $plan, array $keptFieldKeys, string $correlationId): array
    {
        $keptFields = $plan->keptFields($keptFieldKeys);
        if ($keptFields === []) {
            throw new \RuntimeException('No fields selected for apply.', 1712003100);
        }

        $appliedFieldKeys = array_map(static fn(ToolPlanField $field): string => $field->key, $keptFields);
        $affected = [];

        switch ($plan->action) {
            case 'create':
                $affected[] = $this->applyPlanCreate($plan, $keptFields);
                break;
            case 'update':
                $affected = array_merge($affected, $this->applyPlanUpdate($keptFields));
                break;
            case 'delete':
                $affected[] = $this->applyPlanDelete($keptFields);
                break;
            case 'move':
                $affected[] = $this->applyPlanMove($keptFields, $plan->context);
                break;
            case 'copy':
                $affected[] = $this->applyPlanCopy($keptFields, $plan->context);
                break;
            default:
                throw new \RuntimeException('Unsupported plan action: ' . $plan->action, 1712003101);
        }

        return [
            'correlationId' => $correlationId,
            'action' => $plan->action,
            'appliedFieldKeys' => $appliedFieldKeys,
            'affected' => $affected,
        ];
    }

    /**
     * @param list<ToolPlanField> $keptFields
     * @return array{table: string, uid: int}
     */
    private function applyPlanCreate(ToolPlan $plan, array $keptFields): array
    {
        $table = '';
        foreach ($keptFields as $field) {
            $table = $field->table;
            break;
        }
        if ($table === '') {
            throw new \RuntimeException('Create plan is missing table context.', 1712003102);
        }

        $pid = (int) ($plan->context['pid'] ?? 0);
        if ($pid === 0) {
            throw new \RuntimeException('Create plan is missing pid.', 1712003103);
        }
        if ($pid > 0) {
            $this->recordService->assertParentPageExists($pid);
        } else {
            $this->recordService->assertInsertAfterExists($table, abs($pid));
        }

        $fields = [];
        foreach ($keptFields as $field) {
            if ($field->field === '_record') {
                continue;
            }
            $fields[$field->field] = $field->proposedValue;
        }

        if ($fields === []) {
            throw new \RuntimeException('Create plan has no writable fields kept.', 1712003104);
        }

        $newUid = $this->createRecord($table, $pid, $fields);

        return ['table' => $table, 'uid' => $newUid];
    }

    /**
     * @param list<ToolPlanField> $keptFields
     * @return list<array{table: string, uid: int}>
     */
    private function applyPlanUpdate(array $keptFields): array
    {
        /** @var array<string, array<int, array<string, mixed>>> $grouped */
        $grouped = [];
        foreach ($keptFields as $field) {
            if ($field->field === '_record') {
                continue;
            }
            $grouped[$field->table][$field->uid][$field->field] = $field->proposedValue;
        }

        $affected = [];
        foreach ($grouped as $table => $records) {
            foreach ($records as $uid => $fields) {
                if ($fields === []) {
                    continue;
                }
                $this->updateRecord($table, (int) $uid, $fields);
                $affected[] = ['table' => $table, 'uid' => (int) $uid];
            }
        }

        return $affected;
    }

    /**
     * @param list<ToolPlanField> $keptFields
     * @return array{table: string, uid: int}
     */
    private function applyPlanDelete(array $keptFields): array
    {
        $field = $keptFields[0];
        $this->deleteRecord($field->table, $field->uid);

        return ['table' => $field->table, 'uid' => $field->uid];
    }

    /**
     * @param list<ToolPlanField> $keptFields
     * @param array<string, mixed> $context
     * @return array{table: string, uid: int}
     */
    private function applyPlanMove(array $keptFields, array $context): array
    {
        $field = $keptFields[0];
        $target = (int) ($context['target'] ?? $field->proposedValue ?? 0);
        if ($target > 0) {
            $this->recordService->assertParentPageExists($target);
        } elseif ($target < 0 && $field->table === 'pages') {
            $this->recordService->assertInsertAfterExists('pages', abs($target));
        }
        $this->moveRecord($field->table, $field->uid, $target);

        return ['table' => $field->table, 'uid' => $field->uid];
    }

    /**
     * @param list<ToolPlanField> $keptFields
     * @param array<string, mixed> $context
     * @return array{table: string, uid: int}
     */
    private function applyPlanCopy(array $keptFields, array $context): array
    {
        $field = $keptFields[0];
        $target = (int) ($context['target'] ?? $field->proposedValue ?? 0);
        if ($target > 0) {
            $this->recordService->assertParentPageExists($target);
        }
        $copyTreeDepth = (int) ($context['copyTreeDepth'] ?? 0);
        $newUid = $this->copyRecord($field->table, $field->uid, $target, $copyTreeDepth);

        return ['table' => $field->table, 'uid' => $newUid];
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
