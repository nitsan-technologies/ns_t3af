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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Support;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves a page uid from pageId and/or frontend pageUrl for MCP tools.
 */
readonly class SitePageResolver
{
    public function __construct(
        private SiteFinder $siteFinder,
        private ConnectionPool $connectionPool,
    ) {}

    public function resolve(?int $pageId, string $pageUrl, ?int $sysLanguageUid = null): int
    {
        $hasPageId = $pageId !== null && $pageId > 0;
        $trimmedUrl = trim($pageUrl);

        if (!$hasPageId && $trimmedUrl === '') {
            throw new \RuntimeException('Either pageId or pageUrl must be provided.');
        }

        if ($hasPageId && $trimmedUrl !== '') {
            $resolvedFromUrl = $this->resolveUrlToPageUid($trimmedUrl, $sysLanguageUid ?? 0);
            if ($resolvedFromUrl !== $pageId) {
                throw new \RuntimeException(sprintf(
                    'pageId %d does not match page resolved from pageUrl (uid %d).',
                    $pageId,
                    $resolvedFromUrl,
                ));
            }

            return $pageId;
        }

        if ($hasPageId) {
            return $pageId;
        }

        return $this->resolveUrlToPageUid($trimmedUrl, $sysLanguageUid ?? 0);
    }

    private function resolveUrlToPageUid(string $url, int $languageId = 0): int
    {
        if (!str_contains($url, '://') && !str_starts_with($url, '/')) {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteHost = $site->getBase()->getHost();
                if ($siteHost !== '' && str_starts_with($url, $siteHost)) {
                    $url = 'https://' . $url;
                    break;
                }
            }
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) {
            throw new \RuntimeException('pageUrl is not a valid URL or path.');
        }

        $path = $parsedUrl['path'] ?? '/';
        $path = '/' . trim($path, '/');

        if ($path === '/') {
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (isset($parsedUrl['host'])) {
                    $siteHost = $site->getBase()->getHost();
                    if ($siteHost !== '' && $siteHost !== $parsedUrl['host']) {
                        continue;
                    }
                }

                return $site->getRootPageId();
            }
        }

        $matchedAnySite = false;
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                if (isset($parsedUrl['host'])) {
                    $siteHost = $site->getBase()->getHost();
                    if ($siteHost !== '' && $siteHost !== $parsedUrl['host']) {
                        continue;
                    }
                    $matchedAnySite = true;
                }

                $router = $site->getRouter();
                $request = $this->createServerRequest($site, $path, $languageId);
                $pageArguments = $router->matchRequest($request);

                if ($pageArguments instanceof PageArguments) {
                    return $pageArguments->getPageId();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if (isset($parsedUrl['host']) && !$matchedAnySite) {
            throw new \RuntimeException(sprintf(
                'Could not resolve pageUrl "%s": domain does not match any configured site.',
                $url,
            ));
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $page = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($path)))
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($page)) {
            return (int) ($page['uid'] ?? 0);
        }

        throw new \RuntimeException(sprintf('Could not resolve pageUrl "%s" to a page.', $url));
    }

    private function createServerRequest(Site $site, string $path, int $languageId): ServerRequest
    {
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $baseUri = $site->getBase();
        $uri = $baseUri->withPath($path);

        $serverParams = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => $baseUri->getHost() ?: 'localhost',
            'HTTPS' => $baseUri->getScheme() === 'https' ? 'on' : 'off',
            'SERVER_PORT' => $baseUri->getPort() ?: ($baseUri->getScheme() === 'https' ? 443 : 80),
        ];

        $request = new ServerRequest($uri, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('site', $site);

        try {
            $language = $languageId > 0 ? $site->getLanguageById($languageId) : $site->getDefaultLanguage();
            $request = $request->withAttribute('language', $language);
        } catch (\Throwable) {
            $request = $request->withAttribute('language', $site->getDefaultLanguage());
        }

        $normalizedParams = GeneralUtility::makeInstance(NormalizedParams::class, $serverParams);
        $request = $request->withAttribute('normalizedParams', $normalizedParams);

        return $request;
    }
}
