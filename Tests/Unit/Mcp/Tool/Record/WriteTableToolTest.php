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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Record;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * @internal
 */
final class WriteTableToolTest extends TestCase
{
    private WriteTableTool $tool;

    /** @var array<string, mixed> */
    private array $originalTca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['label' => 'header'],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
            ],
        ];

        $tcaSchemaService = new TcaSchemaService();
        $recordService = $this->createMock(RecordService::class);
        $dataHandlerService = $this->createMock(DataHandlerService::class);

        $this->tool = new WriteTableTool($dataHandlerService, $recordService, $tcaSchemaService);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function rejectsUnknownAction(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('patch', 'tt_content'), true);

        self::assertSame('Invalid action. Use create, update, or delete.', $result['error']);
    }

    #[Test]
    public function rejectsUnknownTable(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('create', 'does_not_exist', '{"pid":1,"header":"Hi"}'), true);

        self::assertSame('Table not found: does_not_exist', $result['error']);
    }

    #[Test]
    public function createRequiresPidInData(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('create', 'tt_content', '{"header":"Hi"}'), true);

        self::assertSame('Create requires numeric "pid" in data.', $result['error']);
    }

    #[Test]
    public function updateRequiresUid(): void
    {
        $this->bootstrapAdminUser();

        $result = json_decode($this->tool->execute('update', 'tt_content', '{"header":"Hi"}', 0), true);

        self::assertSame('Update requires uid > 0.', $result['error']);
    }

    private function bootstrapAdminUser(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('check')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
