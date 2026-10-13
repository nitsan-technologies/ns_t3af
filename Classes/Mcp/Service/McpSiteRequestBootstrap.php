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

namespace NITSAN\NsT3AF\Mcp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Puts the TYPO3 site on {@see $GLOBALS['TYPO3_REQUEST']} for DataHandler page writes.
 *
 * cms-redirects (and slug handling) need the site attribute. Shared by
 * {@see DataHandlerService} and {@see RecordsApply\RecordsApplyService}.
 */
final class McpSiteRequestBootstrap
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @return array{hadRequest: bool, original: mixed}
     */
    public function capture(): array
    {
        return [
            'hadRequest' => isset($GLOBALS['TYPO3_REQUEST']),
            'original' => $GLOBALS['TYPO3_REQUEST'] ?? null,
        ];
    }

    /**
     * @param array{hadRequest?: bool, original?: mixed} $snapshot
     */
    public function restore(array $snapshot): void
    {
        $hadRequest = (bool) ($snapshot['hadRequest'] ?? false);
        $originalRequest = $snapshot['original'] ?? null;

        if ($hadRequest && $originalRequest instanceof ServerRequestInterface) {
            $GLOBALS['TYPO3_REQUEST'] = $originalRequest;

            return;
        }

        if (!$hadRequest) {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }

    /**
     * Attach the site for a live page uid. Returns false when the page has no site
     * (callers may warn and skip auto-redirects instead of failing the batch).
     */
    public function attachForPage(int $pageId): bool
    {
        $livePageId = $this->resolveLivePageUid($pageId);
        if ($livePageId <= 0) {
            return false;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($livePageId);
        } catch (SiteNotFoundException) {
            return false;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('site', $site);

            return true;
        }

        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest((string) $site->getBase()))
            ->withAttribute('site', $site)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return true;
    }

    /**
     * Resolve live page uids touched by a pages datamap/cmdmap and attach sites.
     *
     * @param array<mixed> $datamap
     * @param array<mixed> $cmdmap
     * @return list<string> warnings (e.g. pages with no site)
     */
    public function attachForPagesBatch(array $datamap, array $cmdmap): array
    {
        $pageIds = [];
        foreach (($datamap['pages'] ?? []) as $id => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            if (str_starts_with((string) $id, 'NEW')) {
                $pid = (int) ($fields['pid'] ?? 0);
                if ($pid !== 0) {
                    $pageIds[] = abs($pid);
                }
                continue;
            }
            $pageIds[] = (int) $id;
        }

        foreach (array_keys($cmdmap['pages'] ?? []) as $uid) {
            $pageIds[] = (int) $uid;
        }

        $warnings = [];
        foreach (array_unique(array_filter($pageIds, static fn(int $uid): bool => $uid > 0)) as $pageId) {
            if (!$this->attachForPage($pageId)) {
                $warnings[] = sprintf(
                    'Page %d has no site configuration; auto-redirects for slug changes were skipped.',
                    $pageId,
                );
            }
        }

        return $warnings;
    }

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
}
