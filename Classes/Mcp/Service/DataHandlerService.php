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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class DataHandlerService
{
    public function __construct(private SiteFinder $siteFinder) {}

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
                $pid = $this->resolveLivePageUid($pid);
                $fields['pid'] = $pid;
                $this->attachSiteAttribute($pid);
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
                // TYPO3 v12 RootlineUtility cannot resolve workspace version uids; always address live.
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

        $newIds = [];
        $datamap = [];

        foreach ($fileUids as $index => $fileUid) {
            // Placeholder must not contain "_": DataHandler::processRemapStack() treats
            // underscored child ids as "<table>_<uid>" and fails to resolve them. On v12
            // FileExtensionFilter then throws after the reference row is already written
            // (duplicate references on MCP retry); on v13/v14 the reference is silently
            // dropped from the parent field. Same pattern as createRecord().
            $newId = 'NEW' . bin2hex(random_bytes(8));
            $newIds[] = $newId;

            $datamap['sys_file_reference'][$newId] = [
                'uid_local' => $fileUid,
                'uid_foreign' => $recordUid,
                'tablenames' => $table,
                'fieldname' => $fieldName,
                'sorting_foreign' => $index + 1,
                'pid' => 0,
            ];
        }

        $datamap[$table][$recordUid] = [
            $fieldName => implode(',', $newIds),
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

        return $referenceUids;
    }

    /**
     * DataHandler page operations must use the live uid. Workspace version uids break
     * RootlineUtility / SiteFinder on TYPO3 v12 ("Could not fetch page data for uid …").
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
