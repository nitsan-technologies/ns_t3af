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
    public function aChosenWorkspaceIsUsedWhenTheEditorMayUseIt(): void
    {
        self::assertSame(3, AgentWorkspaceTarget::target(3, static fn(int $uid): bool => $uid === 3));
        self::assertFalse(AgentWorkspaceTarget::unusable(3, static fn(int $uid): bool => true));
    }

    #[Test]
    public function liveChosenOrNeverChosenMeansLive(): void
    {
        $canUse = static fn(int $uid): bool => true;

        self::assertSame(0, AgentWorkspaceTarget::target(0, $canUse));
        self::assertSame(0, AgentWorkspaceTarget::target(null, $canUse));
        self::assertFalse(AgentWorkspaceTarget::unusable(0, $canUse));
        self::assertFalse(AgentWorkspaceTarget::unusable(null, $canUse));
    }

    #[Test]
    public function aChosenWorkspaceWithoutAccessIsUnusableAndNotReplaced(): void
    {
        $canUse = static fn(int $uid): bool => false;

        self::assertSame(0, AgentWorkspaceTarget::target(3, $canUse));
        self::assertTrue(AgentWorkspaceTarget::unusable(3, $canUse));
    }
}
