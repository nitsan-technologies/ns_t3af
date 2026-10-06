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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Dynamic;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyPreflight;
use NITSAN\NsT3AF\Mcp\Tool\Dynamic\NsT3afDynamicToolRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Shared uid parsing for *_update_batch, *_delete_batch and *_move_batch.
 * Cap runs before any database lookup (callers invoke parseUids first).
 */
final class NsT3afDynamicToolRegistrarParseUidsTest extends TestCase
{
    #[Test]
    #[DataProvider('batchToolLabels')]
    public function fiveHundredSuppliedUidsAreAccepted(string $toolLabel): void
    {
        unset($toolLabel);
        $uids = implode(',', range(1, RecordsApplyPreflight::MAX_RECORDS));

        $parsed = NsT3afDynamicToolRegistrar::parseUids($uids);

        self::assertCount(RecordsApplyPreflight::MAX_RECORDS, $parsed);
        self::assertSame(1, $parsed[0]);
        self::assertSame(RecordsApplyPreflight::MAX_RECORDS, $parsed[array_key_last($parsed)]);
    }

    #[Test]
    #[DataProvider('batchToolLabels')]
    public function fiveHundredAndOneSuppliedUidsAreRefusedBeforeLookup(string $toolLabel): void
    {
        unset($toolLabel);
        $uids = implode(',', range(1, RecordsApplyPreflight::MAX_RECORDS + 1));

        try {
            NsT3afDynamicToolRegistrar::parseUids($uids);
            self::fail('Expected more than 500 uids to be refused.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('at most 500 uids per call', $exception->getMessage());
            self::assertStringContainsString((string) (RecordsApplyPreflight::MAX_RECORDS + 1), $exception->getMessage());
        }
    }

    /** @return \Generator<string, array{0: string}> */
    public static function batchToolLabels(): \Generator
    {
        yield 'update_batch' => ['update_batch'];
        yield 'delete_batch' => ['delete_batch'];
        yield 'move_batch' => ['move_batch'];
    }
}
