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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentDraftService;
use NITSAN\NsT3AF\Agent\Service\AgentLowRiskFieldMatrix;
use NITSAN\NsT3AF\Agent\Service\AgentRecordLabeler;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentDraftServiceRenameLabelTest extends TestCase
{
    #[Test]
    public function folderRenameCardUsesTheFolderName(): void
    {
        $card = $this->service('New File', 'Rename')->buildDraftCard(new ToolPlan(
            'rename',
            'directory_rename',
            [new ToolPlanField('sys_file:0:_rename', 'sys_file', 0, '_rename', '/user_upload/reports/', 'rename to archive')],
        ), 'write');

        self::assertSame('reports', $card['fields'][0]['recordLabel']);
        self::assertSame('Rename', $card['fields'][0]['fieldLabel']);
        self::assertSame('rename to archive', $card['fields'][0]['proposed']);
    }

    #[Test]
    public function fileRenameCardKeepsTheResolvedRecordLabel(): void
    {
        $card = $this->service('File „test.txt“', 'Rename')->buildDraftCard(new ToolPlan(
            'rename',
            'file_rename',
            [new ToolPlanField('sys_file:12:_rename', 'sys_file', 12, '_rename', '/user_upload/test.txt', 'rename to renamed.txt')],
        ), 'write');

        self::assertSame('File „test.txt“', $card['fields'][0]['recordLabel']);
        self::assertSame('Rename', $card['fields'][0]['fieldLabel']);
    }

    private function service(string $recordLabel, string $fieldLabel): AgentDraftService
    {
        $labeler = $this->createMock(AgentRecordLabeler::class);
        $labeler->method('recordLabel')->willReturn($recordLabel);
        $labeler->method('fieldLabel')->willReturn($fieldLabel);
        $labeler->method('displayValue')->willReturnCallback(static fn(string $field, string $value): string => $value);

        return new AgentDraftService(new AgentLowRiskFieldMatrix(), $labeler);
    }
}
