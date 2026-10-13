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

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Defers {@see processClearCacheQueue()} until after the outer DB transaction commits.
 *
 * Cache backends that TRUNCATE (Typo3DatabaseBackend::flush) cause an implicit MySQL
 * commit and break records_apply dry-run / all-or-nothing rollback — notably when
 * saving sys_category triggers a broad cache flush.
 */
final class DeferredCacheClearingDataHandler extends DataHandler
{
    private bool $deferCacheClearing = false;

    public function deferCacheClearing(bool $defer = true): void
    {
        $this->deferCacheClearing = $defer;
    }

    public function flushDeferredCaches(): void
    {
        $this->deferCacheClearing = false;
        $this->processClearCacheQueue();
    }

    /**
     * Drop the deferred queue without flushing (e.g. after a dry-run rollback).
     */
    public function discardDeferredCaches(): void
    {
        $this->deferCacheClearing = false;
        static::$recordsToClearCacheFor = [];
        static::$recordPidsForDeletedRecords = [];
    }

    protected function processClearCacheQueue(): void
    {
        if ($this->deferCacheClearing) {
            return;
        }

        parent::processClearCacheQueue();
    }
}
