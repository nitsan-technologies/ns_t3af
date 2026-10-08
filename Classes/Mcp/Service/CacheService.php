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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

readonly class CacheService
{
    public function __construct(private CacheManager $cacheManager) {}

    /**
     * Why the current backend user may not clear this scope, or null when allowed. Follows the core cache
     * menu: admins may clear everything; editors need options.clearCache.all for "all" and
     * options.clearCache.pages (on unless switched off) for page caches, and a single page needs edit rights.
     */
    public function denialReason(string $scope, int $pageId = 0): ?string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return 'No backend user: clearing caches is not allowed.';
        }
        if ($user->isAdmin()) {
            return null;
        }
        $options = (array) ($user->getTSConfig()['options.']['clearCache.'] ?? []);

        if ($scope === 'all') {
            return !empty($options['all'])
                ? null
                : 'Only administrators (or users with options.clearCache.all) may clear all caches. Use scope "pages" instead.';
        }
        if (($options['pages'] ?? 1) === 0 || ($options['pages'] ?? 1) === '0') {
            return 'You are not allowed to clear page caches.';
        }
        if ($scope === 'page' && BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_EDIT)) === false) {
            return 'You may not clear the cache of page ' . $pageId . ' because you cannot edit it.';
        }

        return null;
    }

    public function flushPageCaches(): void
    {
        $this->cacheManager->flushCachesInGroup('pages');
    }

    public function flushAllCaches(): void
    {
        $this->cacheManager->flushCaches();
    }

    public function flushPageCache(int $pageId): void
    {
        $this->cacheManager->flushCachesInGroupByTag('pages', 'pageId_' . $pageId);
    }
}
