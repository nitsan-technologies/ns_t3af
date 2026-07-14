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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Domain\Model\BrandContextProfile;
use NITSAN\NsT3AF\Domain\Repository\BrandContextProfileRepositoryInterface;

/**
 * Resolves the brand context profile for runtime injection (site default or per-extension override).
 *
 * @internal
 */
final class BrandContextResolver
{
    public function __construct(
        private readonly BrandContextProfileRepositoryInterface $profiles,
        private readonly SiteStorageContext $siteStorageContext,
        private readonly BrandContextProfileOverrideReaderInterface $profileOverrideReader,
    ) {}

    public function resolveDefaultForPageId(?int $pageId): ?BrandContextProfile
    {
        return $this->resolveForPageId($pageId, null);
    }

    public function resolveForPageId(?int $pageId, ?string $extensionKey, ?string $scope = null): ?BrandContextProfile
    {
        if ($pageId === null || $pageId <= 0) {
            return null;
        }

        $storagePid = $this->siteStorageContext->resolveStoragePidFromPageId($pageId);
        if ($storagePid === null || $storagePid <= 0) {
            return null;
        }

        $extensionKey = trim((string) $extensionKey);
        if ($extensionKey !== '') {
            $overrideUid = $this->profileOverrideReader->resolveProfileUid($storagePid, $extensionKey, (string) $scope);
            if ($overrideUid > 0) {
                $profile = $this->profiles->findByUid($overrideUid);
                if ($profile !== null && $this->profiles->belongsToStorage($overrideUid, $storagePid)) {
                    return $profile;
                }
            }
        }

        return $this->profiles->findDefault($storagePid);
    }
}
