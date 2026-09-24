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

use NITSAN\NsT3AF\Agent\Service\AgentWorkspaceTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Agent changes from Live go into a draft workspace the editor may use.
 *
 * @internal
 */
final class AgentWorkspaceTargetTest extends TestCase
{
    #[Test]
    public function configuredWorkspaceIsUsedOnlyWithAccess(): void
    {
        $never = static fn(): array => throw new \LogicException('not needed');

        self::assertSame(3, AgentWorkspaceTarget::pick(3, $never, static fn(int $uid): bool => $uid === 3));
        self::assertSame(0, AgentWorkspaceTarget::pick(3, $never, static fn(int $uid): bool => false));
    }

    #[Test]
    public function otherwiseTheFirstAccessibleWorkspace(): void
    {
        $candidates = static fn(): array => [1, 2, 5];

        self::assertSame(2, AgentWorkspaceTarget::pick(0, $candidates, static fn(int $uid): bool => $uid >= 2));
        self::assertSame(0, AgentWorkspaceTarget::pick(0, $candidates, static fn(int $uid): bool => false));
        self::assertSame(0, AgentWorkspaceTarget::pick(0, static fn(): array => [], static fn(int $uid): bool => true));
    }
}
