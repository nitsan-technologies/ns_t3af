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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileSource;
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

    private RecordsApplyFileSource&MockObject $fileSource;

    private RecordsApplyTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->createMock(RecordsApplyService::class);
        $this->fileSource = $this->createMock(RecordsApplyFileSource::class);
        $this->tool = new RecordsApplyTool($this->service, $this->fileSource);
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
                [['table' => 'tt_content', 'uids' => [1, 2], 'set' => ['hidden' => 1]]],
                'records_apply',
                '',
            )
            ->willReturn(new RecordsApplyResult('ra-abc', true, false, ['NEWc' => 41], [], ['tt_content' => ['create' => 1, 'delete' => 1]], []));

        $response = json_decode(
            $this->tool->execute(
                '{"tt_content":{"NEWc":{"pid":1,"header":"Hi"}}}',
                '{"tt_content":{"7":{"delete":1}}}',
                true,
                false,
                false,
                '[{"table":"tt_content","uids":[1,2],"set":{"hidden":1}}]',
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
            ->with([], [], false, true, true, [], 'records_apply', '')
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
    public function theRequestIdReachesTheServiceTrimmed(): void
    {
        $this->service->expects(self::once())
            ->method('apply')
            ->with([], [], false, true, true, [], 'records_apply', 'import-2026-10-05')
            ->willReturn(new RecordsApplyResult('ra-abc', false, true, [], [], [], []));

        $this->tool->execute('{}', '{}', false, true, true, '[]', '  import-2026-10-05 ');
    }

    #[Test]
    public function aReplayedAnswerSaysSo(): void
    {
        $this->service->method('apply')->willReturn(
            (new RecordsApplyResult('ra-abc', false, true, ['NEWc' => 41], [], [], []))->withReplayed(),
        );

        $response = json_decode($this->tool->execute('{"tt_content":{"NEWc":{"pid":1}}}', '{}', false, true, true, '[]', 'r1'), true);

        self::assertTrue($response['replayed']);
        self::assertSame(['NEWc' => 41], $response['map']);
    }

    #[Test]
    public function aFilePayloadIsReadAndHandedToTheService(): void
    {
        $this->fileSource->expects(self::once())->method('read')->with(77)->willReturn([
            ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'From file']]],
            [],
            [['table' => 'tt_content', 'uids' => [1], 'set' => ['hidden' => 1]]],
        ]);
        $this->service->expects(self::once())
            ->method('apply')
            ->with(
                ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'From file']]],
                [],
                false,
                true,
                true,
                [['table' => 'tt_content', 'uids' => [1], 'set' => ['hidden' => 1]]],
                'records_apply',
                '',
            )
            ->willReturn(new RecordsApplyResult('ra-abc', false, true, ['NEWc' => 41], [], [], []));

        $response = json_decode($this->tool->execute('{}', '{}', false, true, true, '[]', '', 77), true);

        self::assertSame(['NEWc' => 41], $response['map']);
    }

    #[Test]
    public function aFileAndInlineDataTogetherAreRefused(): void
    {
        $this->fileSource->expects(self::never())->method('read');
        $this->service->expects(self::never())->method('apply');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500026);

        $this->tool->execute('{"tt_content":{"NEWc":{"pid":1}}}', '{}', false, true, true, '[]', '', 77);
    }

    #[Test]
    public function aFileAndInlineBulkTogetherAreRefused(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500026);

        $this->tool->execute('{}', '{}', false, true, true, '[{"table":"tt_content","uids":[1],"set":{"hidden":1}}]', '', 77);
    }

    #[Test]
    public function theInlineByteCapDoesNotApplyToAFile(): void
    {
        $this->fileSource->method('read')->willReturn([['tt_content' => ['NEWc' => ['pid' => 1]]], [], []]);
        $this->service->method('apply')->willReturn(new RecordsApplyResult('ra-abc', false, true, [], [], [], []));

        $response = json_decode($this->tool->execute('{}', '{}', false, true, true, '[]', '', 5), true);

        self::assertTrue($response['ok']);
    }

    #[Test]
    public function aRefusalToReadTheFileStaysAToolError(): void
    {
        $this->fileSource->method('read')->willThrowException(new ToolCallException('File 77 was not found or is not accessible to you. Nothing was written.', 1790500027));
        $this->service->expects(self::never())->method('apply');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500027);

        $this->tool->execute('{}', '{}', false, true, true, '[]', '', 77);
    }

    #[Test]
    public function theNumberOfRecordsMarkedAsAiIsReportedWhenThereAreAny(): void
    {
        $this->service->method('apply')->willReturn(new RecordsApplyResult('ra-abc', false, true, ['NEWc' => 41], [], [], [], 3));

        $response = json_decode($this->tool->execute('{"tt_content":{"NEWc":{"pid":1}}}'), true);

        self::assertSame(3, $response['aiLabelled']);
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
    public function aBulkThatIsNotAListIsAToolError(): void
    {
        $this->service->expects(self::never())->method('apply');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('bulk must be a JSON list');

        $this->tool->execute('{}', '{}', false, true, true, '{"table":"tt_content"}');
    }

    #[Test]
    public function brokenBulkJsonIsAToolError(): void
    {
        $this->service->expects(self::never())->method('apply');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('bulk must be a JSON list');

        $this->tool->execute('{}', '{}', false, true, true, '[{oops');
    }

    #[Test]
    public function aRequestOverTheByteCapIsRefusedBeforeItIsDecoded(): void
    {
        $this->service->expects(self::never())->method('apply');

        $big = '{"tt_content":{"NEWc":{"pid":1,"bodytext":"' . str_repeat('x', RecordsApplyTool::MAX_PAYLOAD_BYTES) . '"}}}';

        try {
            $this->tool->execute($big);
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('too large', $exception->getMessage());
            self::assertStringContainsString('Nothing was written', $exception->getMessage());
            self::assertStringNotContainsString('xxxxxxxx', $exception->getMessage());
        }
    }

    #[Test]
    public function theByteCapCountsDataCmdAndBulkTogether(): void
    {
        $this->service->expects(self::never())->method('apply');

        $half = str_repeat('x', (int) (RecordsApplyTool::MAX_PAYLOAD_BYTES / 2));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('too large');

        $this->tool->execute($half, $half, false, true, true, '[]');
    }

    #[Test]
    public function aRequestJustUnderTheByteCapIsAccepted(): void
    {
        $this->service->expects(self::once())->method('apply')
            ->willReturn(new RecordsApplyResult('ra-abc', false, true, [], [], [], []));

        $padding = str_repeat('x', RecordsApplyTool::MAX_PAYLOAD_BYTES - 100);

        $this->tool->execute('{"tt_content":{"NEWc":{"pid":1,"header":"' . $padding . '"}}}');
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

    #[Test]
    public function anEmptyMapIsAnObjectNotAList(): void
    {
        $this->service->method('apply')->willReturn(new RecordsApplyResult('ra-abc', false, true, [], [], ['tt_content' => ['update' => 1]], []));

        $json = $this->tool->execute('{"tt_content":{"5":{"header":"x"}}}');

        self::assertStringContainsString('"map":{}', $json);
    }

    #[Test]
    public function theWorkspaceTheCallRanInIsReported(): void
    {
        $this->service->method('apply')->willReturn(
            (new RecordsApplyResult('ra-abc', true, false, [], [], [], []))->withWorkspace(2, 'QA Draft WS'),
        );

        $response = json_decode($this->tool->execute('{"tt_content":{"5":{"header":"x"}}}', '{}', true), true);

        self::assertSame(['id' => 2, 'title' => 'QA Draft WS'], $response['workspace']);
    }

    #[Test]
    public function aStoredAnswerKeepsItsWorkspaceWhenReplayed(): void
    {
        $result = (new RecordsApplyResult('ra-abc', false, true, ['NEWa' => 5], [], [], []))->withWorkspace(0, 'Live');

        $again = RecordsApplyResult::fromStored($result->toArray());

        self::assertSame(['id' => 0, 'title' => 'Live'], $again->workspace);
    }
}
