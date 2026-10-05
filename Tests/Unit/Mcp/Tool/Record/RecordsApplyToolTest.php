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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Tool\Record;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Attribute\McpAgentHidden;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\McpToolSeverityResolver;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use NITSAN\NsT3AF\Mcp\Tool\Record\RecordsApplyTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RecordsApplyToolTest extends TestCase
{
    private RecordsApplyService&MockObject $service;

    private RecordsApplyTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->createMock(RecordsApplyService::class);
        $this->tool = new RecordsApplyTool($this->service);
    }

    #[Test]
    public function itIsADestructiveToolHiddenFromTheAgent(): void
    {
        self::assertSame(
            ToolSeverity::Destructive,
            (new McpToolSeverityResolver())->resolveForHandler($this->tool),
        );
        self::assertNotSame([], (new \ReflectionClass(RecordsApplyTool::class))->getAttributes(McpAgentHidden::class));
        self::assertNotSame([], (new \ReflectionClass(RecordsApplyTool::class))->getAttributes(McpToolSeverity::class));
    }

    #[Test]
    public function theDecodedMapsAndFlagsReachTheService(): void
    {
        $this->service->expects(self::once())
            ->method('apply')
            ->with(
                ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'Hi']]],
                ['tt_content' => [7 => ['delete' => 1]]],
                true,
                false,
                false,
            )
            ->willReturn(new RecordsApplyResult('ra-abc', true, false, ['NEWc' => 41], [], ['tt_content' => ['create' => 1, 'delete' => 1]], []));

        $response = json_decode(
            $this->tool->execute(
                '{"tt_content":{"NEWc":{"pid":1,"header":"Hi"}}}',
                '{"tt_content":{"7":{"delete":1}}}',
                true,
                false,
                false,
            ),
            true,
        );

        self::assertSame(
            [
                'ok' => true,
                'dryRun' => true,
                'written' => false,
                'batchId' => 'ra-abc',
                'operations' => ['tt_content' => ['create' => 1, 'delete' => 1]],
                'map' => ['NEWc' => 41],
            ],
            $response,
        );
    }

    #[Test]
    public function defaultsAreStrictAppendAndNoDryRun(): void
    {
        $this->service->expects(self::once())
            ->method('apply')
            ->with([], [], false, true, true)
            ->willReturn(new RecordsApplyResult('ra-abc', false, true, [], [], [], []));

        $this->tool->execute();
    }

    #[Test]
    public function copiesAndIgnoredFieldsAreReportedWhenThereAreAny(): void
    {
        $this->service->method('apply')->willReturn(new RecordsApplyResult(
            'ra-abc',
            false,
            true,
            [],
            ['tt_content' => [7 => 70]],
            [],
            [['table' => 'tt_content', 'id' => 'NEWc', 'fields' => ['bogus']]],
        ));

        $response = json_decode($this->tool->execute('{"tt_content":{"NEWc":{"pid":1}}}'), true);

        self::assertSame(['tt_content' => [7 => 70]], $response['copied']);
        self::assertSame('bogus', $response['ignoredFields'][0]['fields'][0]);
    }

    #[Test]
    public function brokenJsonIsAToolError(): void
    {
        $this->service->expects(self::never())->method('apply');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('data must be a JSON object');

        $this->tool->execute('{not json');
    }

    #[Test]
    public function aListInsteadOfAnObjectIsAToolError(): void
    {
        $this->service->expects(self::never())->method('apply');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('cmd must be a JSON object');

        $this->tool->execute('{}', '[1,2]');
    }

    #[Test]
    public function aRefusedRequestBecomesAToolErrorListingTheProblems(): void
    {
        $this->service->method('apply')->willThrowException(
            new RecordsApplyValidationException([['table' => 'tt_content', 'id' => 'NEWc', 'error' => 'A new record needs a pid.']], 3),
        );

        try {
            $this->tool->execute('{"tt_content":{"NEWc":{"header":"Hi"}}}');
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException $exception) {
            $payload = json_decode($exception->getMessage(), true);

            self::assertFalse($payload['ok']);
            self::assertFalse($payload['written']);
            self::assertSame('validation', $payload['stage']);
            self::assertSame('A new record needs a pid.', $payload['problems'][0]['error']);
            self::assertSame(3, $payload['moreProblems']);
        }
    }
}
