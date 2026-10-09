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

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyBatchRunner;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RecordsApplyBatchRunnerTest extends TestCase
{
    private RecordsApplyService&MockObject $service;

    private RecordsApplyBatchRunner $runner;

    protected function setUp(): void
    {
        $this->service = $this->createMock(RecordsApplyService::class);
        $this->runner = new RecordsApplyBatchRunner($this->service);
    }

    #[Test]
    public function theBatchRunsNonStrictWithoutAppendOrderingUnderTheToolName(): void
    {
        $result = new RecordsApplyResult('ra-abc', false, true, [], [], [], []);
        $this->service->expects(self::once())
            ->method('apply')
            ->with([], ['tt_content' => [1 => ['delete' => 1], 2 => ['delete' => 1]]], false, false, false, [], 'content_delete_batch')
            ->willReturn($result);

        self::assertSame($result, $this->runner->run('content_delete_batch', [], ['tt_content' => [1 => ['delete' => 1], 2 => ['delete' => 1]]]));
    }

    #[Test]
    public function aRefusalByTheEngineBecomesAToolErrorListingTheProblems(): void
    {
        $this->service->method('apply')->willThrowException(
            new RecordsApplyValidationException([['table' => 'tt_content', 'id' => '5', 'error' => 'Record not found or not accessible.']]),
        );

        try {
            $this->runner->run('content_update_batch', ['tt_content' => [5 => ['hidden' => 1]]], []);
            self::fail('Expected a ToolCallException.');
        } catch (ToolCallException $exception) {
            $payload = json_decode($exception->getMessage(), true);
            self::assertFalse($payload['ok']);
            self::assertFalse($payload['written']);
            self::assertSame('validation', $payload['stage']);
            self::assertSame('5', $payload['problems'][0]['id']);
        }
    }

    #[Test]
    public function aFailureOfTheEnginePassesThroughUnchanged(): void
    {
        $this->service->method('apply')->willThrowException(new ToolCallException('DataHandler refused part of the batch.', 1790500004));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1790500004);

        $this->runner->run('content_delete_batch', [], ['tt_content' => [1 => ['delete' => 1]]]);
    }
}
