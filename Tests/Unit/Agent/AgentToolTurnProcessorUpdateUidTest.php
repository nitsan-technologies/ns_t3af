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

use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentToolTurnProcessorUpdateUidTest extends TestCase
{
    #[Test]
    public function aMissingUidIsTakenFromTheDataTheModelSent(): void
    {
        $arguments = AgentToolTurnProcessor::withUpdateUid(
            ['action' => 'update', 'tableName' => 'pages', 'data' => '{"uid":70,"hidden":0}'],
            'write_table',
            [],
        );

        self::assertSame(70, $arguments['uid']);
    }

    #[Test]
    public function aMissingUidIsTakenFromTheOnePageReadInThisTurn(): void
    {
        $arguments = AgentToolTurnProcessor::withUpdateUid(
            ['action' => 'update', 'tableName' => 'pages', 'data' => ['hidden' => 0]],
            'write_table',
            [
                ['role' => 'user', 'content' => 'Make the German version visible', 'meta' => ['type' => 'message']],
                $this->read('pages_get', 70),
            ],
        );

        self::assertSame(70, $arguments['uid']);
    }

    #[Test]
    public function nothingIsGuessedWhenSeveralRecordsWereRead(): void
    {
        $arguments = AgentToolTurnProcessor::withUpdateUid(
            ['action' => 'update', 'tableName' => 'pages', 'data' => ['hidden' => 0]],
            'write_table',
            [$this->read('pages_get', 70), $this->read('pages_get', 71)],
        );

        self::assertArrayNotHasKey('uid', $arguments);
    }

    #[Test]
    public function aReadFromAnEarlierRequestIsNotUsed(): void
    {
        $arguments = AgentToolTurnProcessor::withUpdateUid(
            ['action' => 'update', 'tableName' => 'pages', 'data' => ['hidden' => 0]],
            'write_table',
            [
                $this->read('pages_get', 70),
                ['role' => 'user', 'content' => 'Another request', 'meta' => ['type' => 'message']],
            ],
        );

        self::assertArrayNotHasKey('uid', $arguments);
    }

    #[Test]
    public function aGivenUidAndOtherActionsAreLeftAlone(): void
    {
        $update = ['action' => 'update', 'tableName' => 'pages', 'uid' => 5, 'data' => ['hidden' => 0]];
        self::assertSame($update, AgentToolTurnProcessor::withUpdateUid($update, 'write_table', [$this->read('pages_get', 70)]));

        $create = ['action' => 'create', 'tableName' => 'tt_content', 'data' => ['pid' => 2]];
        self::assertSame($create, AgentToolTurnProcessor::withUpdateUid($create, 'write_table', [$this->read('pages_get', 70)]));
    }

    #[Test]
    public function aRefusedPlanKeepsItsRequestInTheTraceWithLongValuesShortened(): void
    {
        $request = AgentToolTurnProcessor::refusedRequestForTrace([
            'action' => 'create',
            'data' => ['bodytext' => str_repeat('a', 800)],
        ]);

        self::assertSame('create', $request['action']);
        self::assertStringEndsWith('(shortened)', $request['data']['bodytext']);
        self::assertLessThan(600, mb_strlen($request['data']['bodytext']));
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function read(string $tool, int $uid): array
    {
        return [
            'role' => 'assistant',
            'content' => 'read',
            'meta' => ['type' => 'tool_result', 'tool' => $tool, 'success' => true, 'details' => ['uid' => $uid]],
        ];
    }
}
