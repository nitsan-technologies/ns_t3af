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

use NITSAN\NsT3AF\Registry\ExtensionSettingsStorageProbeRegistry;
use NITSAN\NsT3AF\Settings\ExtensionSettingsRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Resolves per-site extension settings storage pid without falling back to pid=0.
 *
 * @internal
 */
final class SiteExtensionSettingsResolver
{
    public function __construct(
        private readonly SiteStorageContext $siteStorageContext,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionSettingsRepository $extensionSettingsRepository,
        private readonly ExtensionSettingsStorageProbeRegistry $probeRegistry,
    ) {}

    public function resolve(
        ?int $pageId = null,
        bool $allowSiteScan = true,
        string $extensionKey = '',
    ): ?int {
        if ($pageId !== null && $pageId > 0) {
            $storagePid = $this->siteStorageContext->resolveStoragePidFromPageId($pageId);
            if ($storagePid !== null && $storagePid > 0) {
                return $storagePid;
            }
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $resolution = $this->siteStorageContext->resolveFromRequest($request);
            if ($resolution->isResolved()) {
                return $resolution->storagePid;
            }
        }

        if (!$allowSiteScan) {
            return null;
        }

        if ($extensionKey !== '') {
            $probeKeys = $this->probeRegistry->probeKeysForExtension($extensionKey);
            if ($probeKeys !== []) {
                $found = $this->findFirstSiteWithConfiguredKeys($extensionKey, $probeKeys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        foreach ($this->probeRegistry->getAvailableProviders() as $probeProvider) {
            $probeKeys = $probeProvider->getProbeKeys();
            if ($probeKeys === []) {
                continue;
            }
            $found = $this->findFirstSiteWithConfiguredKeys($probeProvider->getExtensionKey(), $probeKeys);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<string> $probeKeys
     */
    private function findFirstSiteWithConfiguredKeys(string $extensionKey, array $probeKeys): ?int
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $storagePid = $site->getRootPageId();
            if ($storagePid <= 0) {
                continue;
            }
            if ($this->siteHasAnyConfiguredKey($extensionKey, $storagePid, $probeKeys)) {
                return $storagePid;
            }
        }

        return null;
    }

    /**
     * @param list<string> $probeKeys
     */
    private function siteHasAnyConfiguredKey(string $extensionKey, int $storagePid, array $probeKeys): bool
    {
        $row = $this->extensionSettingsRepository->findByExtensionKey($extensionKey, $storagePid);
        if ($row === null) {
            return false;
        }

        $decoded = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        if (!is_array($decoded)) {
            return false;
        }

        foreach ($probeKeys as $key) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }
}
