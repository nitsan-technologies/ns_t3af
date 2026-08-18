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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use PHPUnit\Framework\TestCase;

final class CaptureQueueSchemaTest extends TestCase
{
    public function testGenerationQueueStoresNoPromptOrContentColumns(): void
    {
        $ddl = (string) file_get_contents(dirname(__DIR__, 3) . '/ext_tables.sql');
        self::assertSame(1, preg_match('/CREATE TABLE tx_nst3af_ailabel_generation \((.*?)\);/s', $ddl, $matches));
        $tableDdl = strtolower((string) ($matches[1] ?? ''));
        self::assertNotSame('', $tableDdl);
        self::assertStringNotContainsString('prompt', $tableDdl);
        self::assertStringNotContainsString('content', $tableDdl);
        self::assertStringNotContainsString('bodytext', $tableDdl);
    }
}
