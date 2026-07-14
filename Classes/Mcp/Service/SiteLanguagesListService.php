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

namespace NITSAN\NsT3AF\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Support\SitePageResolver;
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
        $resolvedPageId = $this->pageResolver->resolve($pageId, $pageUrl);
        $site = $this->siteFinder->getSiteByPageId($resolvedPageId);
        $siteLanguages = $site->getAvailableLanguages($GLOBALS['BE_USER'], false, $resolvedPageId);

        $languages = [];
        foreach ($siteLanguages as $siteLanguage) {
            $languageId = $siteLanguage->getLanguageId();
            $languages[] = [
                'languageId' => $languageId,
                'title' => $siteLanguage->getTitle(),
                'locale' => (string) $siteLanguage->getLocale(),
                'iso' => $siteLanguage->getLocale()->getLanguageCode(),
                'flagIdentifier' => $siteLanguage->getFlagIdentifier(),
                'isDefault' => $languageId === 0,
            ];
        }

        return [
            'pageId' => $resolvedPageId,
            'siteIdentifier' => $site->getIdentifier(),
            'languages' => $languages,
        ];
    }
}
