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
}
