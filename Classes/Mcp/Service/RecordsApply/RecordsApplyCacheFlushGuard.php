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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Keeps MySQL/MariaDB transactions alive during records_apply.
 *
 * Typo3DatabaseBackend::flush() TRUNCATEs cache tables, which implicitly COMMITs the
 * outer transaction. Nested DataHandlers (page copy) and extension hooks (e.g. EXT:news
 * flushing cache_news_category) bypass {@see DeferredCacheClearingDataHandler}.
 *
 * While active: swap DB cache backends to NullBackend, wipe CacheManager's live
 * instances, XCLASS DataHandler → DeferredCacheClearingDataHandler, and set the
 * global defer flag so nested DHs also skip processClearCacheQueue.
 */
final class RecordsApplyCacheFlushGuard
{
    private bool $active = false;

    /** @var array<string, array<string, mixed>> */
    private array $savedCacheConfigurations = [];

    /** @var array<string, mixed>|null */
    private ?array $savedDataHandlerObject = null;

    private bool $hadDataHandlerObject = false;

    public function enter(): void
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        DeferredCacheClearingDataHandler::setGloballyDefer(true);

        $this->hadDataHandlerObject = isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]);
        $this->savedDataHandlerObject = $this->hadDataHandlerObject
            ? $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]
            : null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class] = [
            'className' => DeferredCacheClearingDataHandler::class,
        ];

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->savedCacheConfigurations = $this->readCacheConfigurations($cacheManager);
        $swapped = [];
        foreach ($this->savedCacheConfigurations as $identifier => $configuration) {
            $backend = $configuration['backend'] ?? Typo3DatabaseBackend::class;
            if (!is_string($backend) || $backend === '' || $backend === Typo3DatabaseBackend::class) {
                $configuration['backend'] = NullBackend::class;
            }
            $swapped[$identifier] = $configuration;
        }
        $cacheManager->setCacheConfigurations($swapped);
        $this->resetCacheManagerInstances($cacheManager);
    }

    public function leave(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        DeferredCacheClearingDataHandler::setGloballyDefer(false);

        if ($this->hadDataHandlerObject) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class] = $this->savedDataHandlerObject;
        } else {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][DataHandler::class]);
        }
        $this->savedDataHandlerObject = null;
        $this->hadDataHandlerObject = false;

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        if ($this->savedCacheConfigurations !== []) {
            $cacheManager->setCacheConfigurations($this->savedCacheConfigurations);
        }
        $this->resetCacheManagerInstances($cacheManager);
        $this->savedCacheConfigurations = [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readCacheConfigurations(CacheManager $cacheManager): array
    {
        $reflection = new \ReflectionClass($cacheManager);
        $property = $reflection->getProperty('cacheConfigurations');

        /** @var array<string, array<string, mixed>> $configurations */
        $configurations = $property->getValue($cacheManager);

        return $configurations;
    }

    private function resetCacheManagerInstances(CacheManager $cacheManager): void
    {
        $reflection = new \ReflectionClass($cacheManager);
        $caches = $reflection->getProperty('caches');
        $caches->setValue($cacheManager, []);
        $groups = $reflection->getProperty('cacheGroups');
        $groups->setValue($cacheManager, []);
    }
}
