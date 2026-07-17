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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\Backend;

use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpIpAllowlistRepository;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpSecurityService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class McpSecurityServiceTest extends TestCase
{
    public function testAllowsAllWhenAllowlistEmpty(): void
    {
        $repository = $this->createMock(McpIpAllowlistRepository::class);
        $repository->method('findEnabled')->willReturn([]);

        $service = new McpSecurityService(
            $repository,
            $this->createMock(AdvancedSettingsService::class),
        );

        self::assertTrue($service->isIpAllowed('203.0.113.1'));
    }

    public function testDeniesUnknownIpWhenAllowlistConfigured(): void
    {
        $repository = $this->createMock(McpIpAllowlistRepository::class);
        $repository->method('findEnabled')->willReturn([
            ['uid' => 1, 'label' => 'Office', 'cidr' => '10.0.0.0/8', 'enabled' => true, 'crdate' => 0],
        ]);

        $service = new McpSecurityService(
            $repository,
            $this->createMock(AdvancedSettingsService::class),
        );

        self::assertFalse($service->isIpAllowed('203.0.113.1'));
        self::assertTrue($service->isIpAllowed('10.1.2.3'));
    }

    public function testSkipsMtlsWhenDisabled(): void
    {
        $service = new McpSecurityService(
            $this->createMock(McpIpAllowlistRepository::class),
            $this->createMock(AdvancedSettingsService::class),
        );

        $request = $this->createMock(ServerRequestInterface::class);
        self::assertTrue($service->validateMtls($request));
    }
}
