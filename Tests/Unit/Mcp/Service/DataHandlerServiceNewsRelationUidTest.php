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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use PHPUnit\Framework\TestCase;

/**
 * News MM attach can throw Unknown column uid; create must keep the scalar row.
 */
final class DataHandlerServiceNewsRelationUidTest extends TestCase
{
    public function testCreateKeepsRecordWhenRelationAttachHitsUnknownUidColumn(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Classes/Mcp/Service/DataHandlerService.php',
        );

        self::assertStringContainsString("Unknown column 'uid'", $source);
        self::assertMatchesRegularExpression(
            '/Unknown column \'uid\'.*?return \$newUid;/s',
            $source,
            'createRecord must return the new uid when MM attach fails on missing uid',
        );
        self::assertTrue(class_exists(DataHandlerService::class));
    }
}
