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

namespace NITSAN\NsT3AF\Agent\Service;

use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Held while an agent turn runs; released explicitly or, at the latest, when destroyed.
 *
 * @internal
 */
final class AgentTurnLease
{
    public function __construct(private ?LockingStrategyInterface $locker) {}

    public function release(): void
    {
        $locker = $this->locker;
        $this->locker = null;
        if ($locker === null) {
            return;
        }
        try {
            if ($locker->isAcquired()) {
                $locker->release();
            }
        } catch (\Throwable) {
            // Nothing sensible to do; the OS lock goes away with the request anyway.
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
