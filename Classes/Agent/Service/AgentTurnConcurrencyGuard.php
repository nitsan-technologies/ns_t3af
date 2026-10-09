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

use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * One running agent turn per backend user.
 *
 * A turn holds a PHP worker for the whole model run. Several turns started at once (extra
 * tabs, repeated sends) occupy every worker and make the rest of the backend crawl, so a
 * second turn of the same user is refused while the first is still running. The lock is an
 * OS-level lock: it is released when the request ends, even if the process dies. If locking
 * is not available the turn simply runs (fail open).
 *
 * @internal
 */
final readonly class AgentTurnConcurrencyGuard
{
    public function __construct(private LockFactory $lockFactory) {}

    /**
     * @return AgentTurnLease|null null when another turn of this user is already running
     */
    public function acquire(int $backendUserId): ?AgentTurnLease
    {
        $capabilities = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

        try {
            $locker = $this->lockFactory->createLocker('nst3af-agent-turn-' . max(0, $backendUserId), $capabilities);
            if (!$locker->acquire($capabilities)) {
                return null;
            }
        } catch (LockAcquireWouldBlockException) {
            return null;
        } catch (\Throwable) {
            return new AgentTurnLease(null);
        }

        return new AgentTurnLease($locker);
    }
}
