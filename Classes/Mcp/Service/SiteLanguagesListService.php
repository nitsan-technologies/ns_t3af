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

use NITSAN\NsT3AF\Mcp\Support\SitePageResolver;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
readonly class SiteLanguagesListService
{
    public function __construct(
        private SitePageResolver $pageResolver,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listForPage(?int $pageId, string $pageUrl): array
    {
        $resolvedPageId = 0;
        $site = null;
        if (($pageId ?? 0) > 0 || trim($pageUrl) !== '') {
            try {
                $resolvedPageId = $this->pageResolver->resolve($pageId, $pageUrl);
                $site = $this->siteFinder->getSiteByPageId($resolvedPageId);
            } catch (SiteNotFoundException) {
                $site = null;
            }
        }
        if ($site === null) {
            return [
                'note' => ($resolvedPageId > 0
                    ? sprintf('Page %d belongs to no site. These are the languages of each site.', $resolvedPageId)
                    : 'No page given. These are the languages of each site.')
                    . ' A language id can stand for a different language on another site.',
                'sites' => $this->allSitesLanguages(),
            ];
        }

        return [
            'pageId' => $resolvedPageId,
            'siteIdentifier' => $site->getIdentifier(),
            'languages' => array_map(
                $this->languageRow(...),
                $site->getAvailableLanguages($GLOBALS['BE_USER'], false, $resolvedPageId),
            ),
        ];
    }

    /**
     * @return list<array{siteIdentifier: string, rootPageId: int, languages: list<array<string, mixed>>}>
     */
    private function allSitesLanguages(): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $sites[] = [
                'siteIdentifier' => $site->getIdentifier(),
                'rootPageId' => $site->getRootPageId(),
                'languages' => array_values(array_map(
                    $this->languageRow(...),
                    $site->getAvailableLanguages($GLOBALS['BE_USER']),
                )),
            ];
        }

        return $sites;
    }

    /**
     * @return array<string, mixed>
     */
    private function languageRow(SiteLanguage $siteLanguage): array
    {
        return [
            'languageId' => $siteLanguage->getLanguageId(),
            'title' => $siteLanguage->getTitle(),
            'locale' => (string) $siteLanguage->getLocale(),
            'iso' => $siteLanguage->getLocale()->getLanguageCode(),
            'flagIdentifier' => $siteLanguage->getFlagIdentifier(),
            'isDefault' => $siteLanguage->getLanguageId() === 0,
        ];
    }
}
