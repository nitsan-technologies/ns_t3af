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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentTurnConcurrencyGuard;
use NITSAN\NsT3AF\Agent\Service\AgentTurnLease;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * One agent turn per backend user at a time (ticket 14zervyu2k5).
 *
 * @internal
 */
final class AgentTurnConcurrencyGuardTest extends TestCase
{
    #[Test]
    public function freeLockIsAcquiredAndReleasedExactlyOnce(): void
    {
        $locker = $this->locker(acquired: true);
        $locker->expects(self::once())->method('release');

        $lease = $this->guard($locker)->acquire(7);

        self::assertInstanceOf(AgentTurnLease::class, $lease);
        $lease->release();
        $lease->release();
        unset($lease);
    }

    #[Test]
    public function leaseIsReleasedWhenItIsDestroyed(): void
    {
        $locker = $this->locker(acquired: true);
        $locker->expects(self::once())->method('release');

        $lease = $this->guard($locker)->acquire(7);
        self::assertInstanceOf(AgentTurnLease::class, $lease);
        $lease = null;
    }

    #[Test]
    public function busyLockRefusesTheSecondTurn(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(false);
        $locker->expects(self::never())->method('release');

        self::assertNull($this->guard($locker)->acquire(7));
    }

    #[Test]
    public function wouldBlockExceptionAlsoMeansBusy(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willThrowException(new LockAcquireWouldBlockException('busy', 1));

        self::assertNull($this->guard($locker)->acquire(7));
    }

    #[Test]
    public function brokenLockingFailsOpenSoTheTurnStillRuns(): void
    {
        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLocker')->willThrowException(new \RuntimeException('no lock backend'));

        $lease = (new AgentTurnConcurrencyGuard($factory))->acquire(7);

        self::assertInstanceOf(AgentTurnLease::class, $lease);
        $lease->release();
    }

    #[Test]
    public function lockIsPerBackendUser(): void
    {
        $locker = $this->locker(acquired: true);
        $factory = $this->createMock(LockFactory::class);
        $factory->expects(self::exactly(2))
            ->method('createLocker')
            ->willReturnCallback(function (string $id) use ($locker): LockingStrategyInterface {
                self::assertContains($id, ['nst3af-agent-turn-7', 'nst3af-agent-turn-8']);

                return $locker;
            });

        $guard = new AgentTurnConcurrencyGuard($factory);
        $guard->acquire(7)?->release();
        $guard->acquire(8)?->release();
    }

    /** @return LockingStrategyInterface&MockObject */
    private function locker(bool $acquired): LockingStrategyInterface
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn($acquired);
        $locker->method('isAcquired')->willReturn($acquired);

        return $locker;
    }

    private function guard(LockingStrategyInterface $locker): AgentTurnConcurrencyGuard
    {
        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLocker')->willReturn($locker);

        return new AgentTurnConcurrencyGuard($factory);
    }
}
