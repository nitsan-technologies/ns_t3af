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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsUndoService;
use NITSAN\NsT3AF\Mcp\Tool\Record\RecordsUndoTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RecordsUndoToolTest extends TestCase
{
    private RecordsUndoService&MockObject $service;

    private RecordsUndoTool $tool;

    protected function setUp(): void
    {
        $this->service = $this->createMock(RecordsUndoService::class);
        $this->tool = new RecordsUndoTool($this->service);
    }

    #[Test]
    public function itIsADestructiveToolHiddenFromTheAgent(): void
    {
        $class = new \ReflectionClass(RecordsUndoTool::class);

        self::assertNotSame([], $class->getAttributes(McpAgentHidden::class));
        $severity = $class->getAttributes(McpToolSeverity::class)[0]->newInstance();
        self::assertSame(ToolSeverity::Destructive, $severity->severity);
    }

    #[Test]
    public function theDescriptionExplainsNotRestoredVersusCreatedRelationRows(): void
    {
        $description = (new \ReflectionMethod(RecordsUndoTool::class, 'execute'))
            ->getAttributes(\Mcp\Capability\Attribute\McpTool::class)[0]
            ->newInstance()
            ->description;

        self::assertIsString($description);
        self::assertStringContainsString('history diff', $description);
        self::assertStringContainsString('notRestored', $description);
        self::assertStringContainsString('file references', $description);
    }

    #[Test]
    public function theBatchIdAndDryRunReachTheService(): void
    {
        $this->service->expects(self::once())
            ->method('undo')
            ->with('ra-0123456789abcdef0123', true)
            ->willReturn([
                'result' => new RecordsApplyResult('ru-aaaaaaaaaaaaaaaaaaaa', true, false, [], [], ['tt_content' => ['delete' => 2]], []),
                'undoes' => 'ra-0123456789abcdef0123',
                'notRestored' => [],
            ]);

        $response = json_decode($this->tool->execute(' ra-0123456789abcdef0123 ', true), true);

        self::assertTrue($response['ok']);
        self::assertTrue($response['dryRun']);
        self::assertFalse($response['written']);
        self::assertSame('ru-aaaaaaaaaaaaaaaaaaaa', $response['batchId']);
        self::assertSame('ra-0123456789abcdef0123', $response['undoes']);
        self::assertArrayNotHasKey('notRestored', $response);
    }

    #[Test]
    public function recordsThatCouldNotBeRestoredAreListed(): void
    {
        $notRestored = [['table' => 'tt_content', 'id' => 7, 'fields' => ['image'], 'reason' => 'relation fields (files, inline records, categories) are not restored']];
        $this->service->method('undo')->willReturn([
            'result' => new RecordsApplyResult('ru-aaaaaaaaaaaaaaaaaaaa', false, true, [], [], [], []),
            'undoes' => 'ra-0123456789abcdef0123',
            'notRestored' => $notRestored,
        ]);

        $response = json_decode($this->tool->execute('ra-0123456789abcdef0123'), true);

        self::assertSame($notRestored, $response['notRestored']);
    }

    #[Test]
    public function aRefusalOfTheServiceStaysAToolError(): void
    {
        $this->service->method('undo')->willThrowException(new ToolCallException('Batch cannot be undone.', 1790500022));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500022);

        $this->tool->execute('ra-0123456789abcdef0123');
    }

    #[Test]
    public function aRefusalOfTheEngineBecomesAToolErrorListingTheProblems(): void
    {
        $this->service->method('undo')->willThrowException(
            new RecordsApplyValidationException([['table' => 'tt_content', 'id' => '7', 'error' => 'Record not found or not accessible.']]),
        );

        try {
            $this->tool->execute('ra-0123456789abcdef0123');
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException $exception) {
            $payload = json_decode($exception->getMessage(), true);
            self::assertFalse($payload['ok']);
            self::assertSame('validation', $payload['stage']);
            self::assertSame('7', $payload['problems'][0]['id']);
        }
    }
}
