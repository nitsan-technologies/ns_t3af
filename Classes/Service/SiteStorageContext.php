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

namespace NITSAN\NsT3AF\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Maps backend page-tree selection to the site root page id used as storage pid for
 * per-site AI Providers and AI Features.
 *
 * @internal
 */
final class SiteStorageContext
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    public function resolvePageIdFromRequest(ServerRequestInterface $request): int
    {
        return self::extractPageIdFromRequest($request);
    }

    /**
     * Resolves a page id for global toolbar links: request query/body, referer, then first site root.
     */
    public function resolvePageIdForToolbarNavigation(ServerRequestInterface $request): int
    {
        $pageId = self::extractPageIdFromRequest($request);
        if ($pageId > 0) {
            return $pageId;
        }

        $pageId = self::extractPageIdFromReferer($request);
        if ($pageId > 0) {
            return $pageId;
        }

        return $this->resolveFirstRootStoragePid() ?? 0;
    }

    public static function extractPageIdFromRequest(ServerRequestInterface $request): int
    {
        $query = $request->getQueryParams();
        $pageId = (int) ($query['id'] ?? $query['pageId'] ?? $query['pid'] ?? 0);
        if ($pageId <= 0) {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $pageId = (int) ($body['id'] ?? $body['pageId'] ?? $body['pid'] ?? 0);
            }
        }

        return $pageId;
    }

    public static function extractPageIdFromReferer(ServerRequestInterface $request): int
    {
        $referer = $request->getServerParams()['HTTP_REFERER'] ?? '';
        if (!is_string($referer) || $referer === '') {
            return 0;
        }

        $query = parse_url($referer, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return 0;
        }

        parse_str($query, $params);

        return (int) ($params['id'] ?? $params['pageId'] ?? $params['pid'] ?? 0);
    }

    public function resolveStoragePidFromPageId(int $pageId): ?int
    {
        if ($pageId <= 0) {
            return null;
        }

        try {
            $rootPageId = $this->siteFinder->getSiteByPageId($pageId)->getRootPageId();

            return $rootPageId > 0 ? $rootPageId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lowest site root page id among configured sites whose root page still exists
     * and is not deleted. Used by Quick setup so wizard data is always stored on
     * the first valid site root instead of pid = 0 or a stale/deleted root.
     */
    public function resolveFirstRootStoragePid(): ?int
    {
        $candidates = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $storagePid = $site->getRootPageId();
            if ($this->isValidStorageRoot($storagePid)) {
                $candidates[] = $storagePid;
            }
        }

        if ($candidates === []) {
            return null;
        }

        sort($candidates, SORT_NUMERIC);

        return $candidates[0];
    }

    private function isValidStorageRoot(int $storagePid): bool
    {
        if ($storagePid <= 0) {
            return false;
        }

        $pageRecord = BackendUtility::getRecord('pages', $storagePid);

        return is_array($pageRecord) && (int) ($pageRecord['deleted'] ?? 0) === 0;
    }

    public function resolveFromRequest(ServerRequestInterface $request): SiteStorageResolution
    {
        $pageId = $this->resolvePageIdFromRequest($request);
        if ($pageId <= 0) {
            return SiteStorageResolution::pageRequired();
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $storagePid = $site->getRootPageId();
            if ($storagePid <= 0) {
                return SiteStorageResolution::pageNotInSite($pageId);
            }

            $siteTitle = $site->getIdentifier();
            $pageRecord = BackendUtility::getRecord('pages', $storagePid);
            if (is_array($pageRecord) && trim((string) ($pageRecord['title'] ?? '')) !== '') {
                $siteTitle = trim((string) $pageRecord['title']);
            }

            return SiteStorageResolution::resolved(
                $storagePid,
                $pageId,
                $site->getIdentifier(),
                $siteTitle,
            );
        } catch (\Throwable) {
            return SiteStorageResolution::pageNotInSite($pageId);
        }
    }

    /**
     * @return list<array{storagePid: int, siteIdentifier: string, siteTitle: string, providerCount: int}>
     */
    public function listConfiguredSites(int $excludeStoragePid = 0): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $storagePid = $site->getRootPageId();
            if ($storagePid <= 0 || $storagePid === $excludeStoragePid) {
                continue;
            }

            $siteTitle = $site->getIdentifier();
            $pageRecord = BackendUtility::getRecord('pages', $storagePid);
            if (is_array($pageRecord) && trim((string) ($pageRecord['title'] ?? '')) !== '') {
                $siteTitle = trim((string) $pageRecord['title']);
            }

            $sites[] = [
                'storagePid' => $storagePid,
                'siteIdentifier' => $site->getIdentifier(),
                'siteTitle' => $siteTitle,
                'providerCount' => 0,
            ];
        }

        usort($sites, static fn(array $a, array $b): int => strcasecmp($a['siteTitle'], $b['siteTitle']));

        return $sites;
    }
}
