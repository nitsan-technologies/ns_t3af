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

use NITSAN\NsT3AF\Mcp\Service\WorkspaceVersionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final class WorkspaceVersionServiceTest extends TestCase
{
    #[Test]
    public function publishNowStageIsRefused(): void
    {
        $service = new WorkspaceVersionService($this->createMock(ConnectionPool::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('workspace_publish');

        $service->assertStageExistsForWorkspace(-20, 3);
    }
}
