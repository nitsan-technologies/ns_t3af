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

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordsApplyFileAccessTest extends TestCase
{
    #[Test]
    public function aFileInsideAMountOfItsStorageIsReadable(): void
    {
        self::assertTrue(RecordsApplyFileAccess::isInsideAFileMount([['identifier' => '1:/user_upload/']], 1, '/user_upload/a.json'));
        self::assertTrue(RecordsApplyFileAccess::isInsideAFileMount([['identifier' => '1:/user_upload/']], 1, '/user_upload/sub/a.json'));
    }

    #[Test]
    public function aFileOutsideEveryMountIsNot(): void
    {
        $mounts = [['identifier' => '1:/user_upload/']];

        self::assertFalse(RecordsApplyFileAccess::isInsideAFileMount($mounts, 1, '/restricted/a.json'));
        // A folder that only starts with the same letters is a different folder.
        self::assertFalse(RecordsApplyFileAccess::isInsideAFileMount($mounts, 1, '/user_upload_private/a.json'));
    }

    #[Test]
    public function aMountOfAnotherStorageDoesNotCount(): void
    {
        self::assertFalse(RecordsApplyFileAccess::isInsideAFileMount([['identifier' => '2:/user_upload/']], 1, '/user_upload/a.json'));
    }

    #[Test]
    public function aMountOnTheStorageRootCoversTheWholeStorage(): void
    {
        self::assertTrue(RecordsApplyFileAccess::isInsideAFileMount([['identifier' => '1:/']], 1, '/anything/a.json'));
    }
}
