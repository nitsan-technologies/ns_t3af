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

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsUndoService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordsUndoServiceTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function batchIds(): iterable
    {
        yield 'records_apply batch' => ['ra-0123456789abcdef0123', true];
        yield 'undo batch' => ['ru-0123456789abcdef0123', true];
        yield 'too short' => ['ra-0123', false];
        yield 'too long' => ['ra-0123456789abcdef01234', false];
        yield 'uppercase' => ['ra-0123456789ABCDEF0123', false];
        yield 'other prefix' => ['xx-0123456789abcdef0123', false];
        yield 'wildcard' => ['ra-0123456789abcdef%123', false];
        yield 'trailing newline' => ["ra-0123456789abcdef0123\n", false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('batchIds')]
    public function onlyIdsTheEngineHandsOutAreAccepted(string $batchId, bool $valid): void
    {
        self::assertSame($valid, RecordsUndoService::isBatchId($batchId));
    }

    #[Test]
    public function theUndoOfABatchHasAFixedIdOfItsOwn(): void
    {
        $undo = RecordsUndoService::undoBatchId('ra-0123456789abcdef0123');

        self::assertSame($undo, RecordsUndoService::undoBatchId('ra-0123456789abcdef0123'));
        self::assertTrue(RecordsUndoService::isBatchId($undo));
        self::assertStringStartsWith('ru-', $undo);
        self::assertNotSame($undo, RecordsUndoService::undoBatchId('ra-0123456789abcdef0124'));
        // Undoing an undo is a different batch from the undone one.
        self::assertNotSame($undo, RecordsUndoService::undoBatchId($undo));
    }
}
