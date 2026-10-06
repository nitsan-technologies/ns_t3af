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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\RecordsApply;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyIdempotency;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the requestId handling that need no database: the id format, what "the same request" means,
 * and how a stored answer comes back.
 */
final class RecordsApplyIdempotencyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validIds(): iterable
    {
        yield 'uuid' => ['3f2b8c1e-9d4a-4b7e-8a61-0c5d2e7f9a10'];
        yield 'plain' => ['import-1'];
        yield 'with colon and dot' => ['job:2026.10.05_a'];
        yield 'longest' => [str_repeat('a', RecordsApplyIdempotency::MAX_REQUEST_ID_LENGTH)];
    }

    #[Test]
    #[DataProvider('validIds')]
    public function aWellFormedRequestIdIsAccepted(string $requestId): void
    {
        RecordsApplyIdempotency::assertValidRequestId($requestId);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', RecordsApplyIdempotency::MAX_REQUEST_ID_LENGTH + 1)];
        yield 'space' => ['my id'];
        yield 'slash' => ['a/b'];
        yield 'quote' => ["a'b"];
        yield 'newline' => ["a\nb"];
        yield 'trailing newline' => ["abc\n"];
        yield 'umlaut' => ['größe'];
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function aMalformedRequestIdIsRefused(string $requestId): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500014);

        RecordsApplyIdempotency::assertValidRequestId($requestId);
    }

    #[Test]
    public function theSamePayloadHasTheSameHash(): void
    {
        $datamap = ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'Hi']]];

        self::assertSame(
            RecordsApplyIdempotency::payloadHash($datamap, [], [], true, true),
            RecordsApplyIdempotency::payloadHash($datamap, [], [], true, true),
        );
    }

    #[Test]
    public function anythingThatChangesWhatIsWrittenChangesTheHash(): void
    {
        $datamap = ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'Hi']]];
        $base = RecordsApplyIdempotency::payloadHash($datamap, [], [], true, true);

        self::assertNotSame($base, RecordsApplyIdempotency::payloadHash(['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'Ho']]], [], [], true, true));
        self::assertNotSame($base, RecordsApplyIdempotency::payloadHash($datamap, ['tt_content' => [7 => ['delete' => 1]]], [], true, true));
        self::assertNotSame($base, RecordsApplyIdempotency::payloadHash($datamap, [], [['table' => 'tt_content', 'uids' => [1], 'set' => ['hidden' => 1]]], true, true));
        self::assertNotSame($base, RecordsApplyIdempotency::payloadHash($datamap, [], [], false, true));
        self::assertNotSame($base, RecordsApplyIdempotency::payloadHash($datamap, [], [], true, false));
    }

    #[Test]
    public function aStoredAnswerComesBackAsAReplay(): void
    {
        $original = new RecordsApplyResult(
            'ra-abc',
            false,
            true,
            ['NEWpage' => 12, 'NEWc' => 13],
            ['tt_content' => [5 => 14]],
            ['pages' => ['create' => 1], 'tt_content' => ['create' => 1, 'copy' => 1]],
            [['table' => 'tt_content', 'id' => 'NEWc', 'fields' => ['bogus']]],
            4,
        );

        $replay = RecordsApplyResult::fromStored(json_decode((string) json_encode($original->toArray()), true))->withReplayed();

        self::assertTrue($replay->replayed);
        self::assertTrue($replay->written);
        self::assertFalse($replay->dryRun);
        self::assertSame('ra-abc', $replay->batchId);
        self::assertSame($original->created, $replay->created);
        self::assertSame($original->copied, $replay->copied);
        self::assertSame($original->operations, $replay->operations);
        self::assertSame($original->ignoredFields, $replay->ignoredFields);

        $array = $replay->toArray();
        self::assertTrue($array['replayed']);
        self::assertArrayNotHasKey('aiLabelled', $array);
    }

    #[Test]
    public function aFirstAnswerIsNotMarkedAsReplayed(): void
    {
        $array = (new RecordsApplyResult('ra-abc', false, true, [], [], [], []))->toArray();

        self::assertArrayNotHasKey('replayed', $array);
    }

    #[Test]
    public function anUnreadableStoredAnswerStillGivesASafeEmptyResult(): void
    {
        $replay = RecordsApplyResult::fromStored([]);

        self::assertSame('', $replay->batchId);
        self::assertSame([], $replay->created);
    }
}
