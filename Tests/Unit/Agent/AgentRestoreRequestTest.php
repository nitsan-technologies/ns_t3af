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

use NITSAN\NsT3AF\Agent\Service\AgentRestoreRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentRestoreRequestTest extends TestCase
{
    #[Test]
    public function puttingTheOldGoogleTextBackIsARestore(): void
    {
        self::assertTrue(AgentRestoreRequest::matches("I don't like it, put the old Google text back"));
        self::assertTrue(AgentRestoreRequest::matches('undo'));
        self::assertTrue(AgentRestoreRequest::matches('Bitte mach das rückgängig'));
        self::assertTrue(AgentRestoreRequest::matches('Setze den alten Google-Text zurück'));
    }

    #[Test]
    public function aRequestForBetterGoogleTextsIsNotARestore(): void
    {
        self::assertFalse(AgentRestoreRequest::matches('make this page look better in Google'));
        self::assertFalse(AgentRestoreRequest::matches("I don't like it, write better Google texts"));
    }

    #[Test]
    public function theLatestChangeThatStillHasUndoIsUsed(): void
    {
        $changeId = AgentRestoreRequest::latestChangeId([
            ['role' => 'assistant', 'meta' => ['changeId' => 'older', 'undoable' => true]],
            ['role' => 'assistant', 'meta' => ['changeId' => 'done', 'undoable' => true, 'undone' => true]],
            ['role' => 'assistant', 'meta' => ['changeId' => 'seo', 'undoable' => true]],
            ['role' => 'user', 'content' => 'put the old Google text back', 'meta' => []],
        ]);

        self::assertSame('seo', $changeId);
    }

    #[Test]
    public function aChangeThatCannotBeUndoneIsSkipped(): void
    {
        self::assertSame('kept', AgentRestoreRequest::latestChangeId([
            ['meta' => ['changeId' => 'kept', 'undoable' => true]],
            ['meta' => ['changeId' => 'no-button', 'undoable' => false]],
        ]));
        self::assertNull(AgentRestoreRequest::latestChangeId([
            ['meta' => ['type' => 'info']],
        ]));
    }

    #[Test]
    public function theLatestAppliedChangeIsReportedWhenItCannotBeUndone(): void
    {
        self::assertSame(
            ['changeId' => 'settings', 'undoable' => false],
            AgentRestoreRequest::latestAppliedChange([
                ['meta' => ['changeId' => 'page', 'undoable' => true]],
                ['meta' => ['changeId' => 'settings', 'undoable' => false]],
            ]),
        );
        self::assertSame(
            ['changeId' => 'page', 'undoable' => true],
            AgentRestoreRequest::latestAppliedChange([
                ['meta' => ['changeId' => 'settings', 'undoable' => false, 'undone' => true]],
                ['meta' => ['changeId' => 'page', 'undoable' => true]],
            ]),
        );
    }

    #[Test]
    public function aConversationWithNoAppliedChangeHasNothingToUndo(): void
    {
        self::assertFalse(AgentRestoreRequest::conversationHasChange([
            ['role' => 'assistant', 'content' => 'Use the Undo button.'],
        ]));
        self::assertTrue(AgentRestoreRequest::conversationHasChange([
            ['meta' => ['changeId' => 'page', 'undone' => true]],
        ]));
    }
}
