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

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyAudit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordsApplyAuditTest extends TestCase
{
    #[Test]
    public function quotedValuesAreKeptOutOfTheLog(): void
    {
        $redact = new \ReflectionMethod(RecordsApplyAudit::class, 'redact');

        self::assertSame(
            "Attempt to modify record '…' (tt_content:5) without permission",
            $redact->invoke(null, "Attempt to modify record 'My secret title' (tt_content:5) without permission"),
        );
        self::assertSame('The value "…" is invalid', $redact->invoke(null, 'The value "abc" is invalid'));
    }

    #[Test]
    public function apostrophesInsideWordsAreLeftAlone(): void
    {
        $redact = new \ReflectionMethod(RecordsApplyAudit::class, 'redact');

        self::assertSame("It's fine, don't worry", $redact->invoke(null, "It's fine, don't worry"));
    }

    #[Test]
    public function aColPosRefusalKeepsTheTypeAndColumnAndHidesTheTitle(): void
    {
        $redact = new \ReflectionMethod(RecordsApplyAudit::class, 'redact');
        $long = str_repeat('x', 100);

        self::assertSame(
            'The record "…" with CType "text" in colPos "3" couldn\'t be saved due to disallowed value(s).',
            $redact->invoke(null, 'The record "Secret title" with CType "text" in colPos "3" couldn\'t be saved due to disallowed value(s).'),
        );
        self::assertSame(
            'CType "' . str_repeat('x', 80) . '"',
            $redact->invoke(null, 'CType "' . $long . '"'),
        );
    }

    #[Test]
    public function validationIsAWarningAndADataHandlerDryRunStaysAnError(): void
    {
        $level = new \ReflectionMethod(RecordsApplyAudit::class, 'level');

        self::assertSame('warning', $level->invoke(null, false, 'validation'));
        self::assertSame('error', $level->invoke(null, false, 'datahandler'));
        self::assertSame('info', $level->invoke(null, true, 'applied'));
    }

    #[Test]
    public function aFailedEntryListsProblemPathsAndRecordIdsWithoutValues(): void
    {
        $details = new \ReflectionMethod(RecordsApplyAudit::class, 'details');
        $payload = $details->invoke(
            null,
            'records_apply',
            'ra-1',
            true,
            false,
            'validation',
            [],
            [],
            'records_apply request rejected: 1 problem(s), nothing was written.',
            [[
                'table' => 'tt_content',
                'id' => '12',
                'fields' => ['header'],
                'error' => 'Unknown field.',
                'value' => 'secret body',
            ]],
            ['tt_content' => ['12', 'NEWc1']],
        );

        self::assertSame([
            ['table' => 'tt_content', 'id' => '12', 'fields' => ['header'], 'error' => 'Unknown field.'],
        ], $payload['problems']);
        self::assertSame(['tt_content' => ['12', 'NEWc1']], $payload['records']);
        self::assertArrayNotHasKey('value', $payload['problems'][0]);
    }
}
