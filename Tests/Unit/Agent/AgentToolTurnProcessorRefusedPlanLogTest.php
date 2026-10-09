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

use NITSAN\NsT3AF\Agent\Service\AgentAuditLogger;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentToolPlanResolver;
use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use NITSAN\NsT3AF\Agent\Service\DynamicToolPlanService;
use NITSAN\NsT3AF\Agent\Service\SatelliteToolPlanService;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolLogRepository;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A change that cannot be prepared (not allowed, wrong arguments) must leave a failed row in the log.
 *
 * @internal
 */
final class AgentToolTurnProcessorRefusedPlanLogTest extends TestCase
{
    use AgentTranslatorTrait;

    /** @var array<string, mixed> */
    private array $originalTca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['label' => 'header'],
            'columns' => ['header' => ['config' => ['type' => 'input']]],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['BE_USER']);
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function aRefusedWriteIsLoggedAsFailedWithTheReason(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $user;

        $rows = [];
        $repository = $this->createMock(McpToolLogRepository::class);
        $repository->method('insert')->willReturnCallback(static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        $message = $this->runWriteTurn($repository, 'corr-refused-1');

        self::assertSame('tool_result', $message['meta']['type'] ?? null);
        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]['success']);
        self::assertSame('write_table', $rows[0]['tool_name']);
        self::assertSame('agent', $rows[0]['call_type']);
        self::assertSame('corr-refused-1', $rows[0]['correlation_id']);
        self::assertStringContainsString('not allowed to change this kind of record', (string) $rows[0]['error_message']);
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function runWriteTurn(McpToolLogRepository $repository, string $correlationId): array
    {
        $processor = $this->buildProcessor($repository);
        $method = (new ReflectionClass(AgentToolTurnProcessor::class))->getMethod('processWriteToolTurn');

        /** @var array{role: string, content: string, meta: array<string, mixed>} $message */
        $message = $method->invoke(
            $processor,
            ['name' => 'write_table', 'executable' => true, 'severity' => ToolSeverity::Write->value],
            [],
            ['arguments' => ['action' => 'create', 'tableName' => 'tt_content', 'data' => '{"pid":1,"header":"X"}']],
            ToolSeverity::Write->value,
            $correlationId,
        );

        return $message;
    }

    private function buildProcessor(McpToolLogRepository $repository): AgentToolTurnProcessor
    {
        $translator = $this->createAgentTranslator();
        $tool = new WriteTableTool(
            $this->createMock(DataHandlerService::class),
            $this->createMock(RecordService::class),
            new TcaSchemaService(),
            $this->createMock(RecordsApplyService::class),
        );
        $resolver = new AgentToolPlanResolver(
            [$tool],
            (new ReflectionClass(DynamicToolPlanService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SatelliteToolPlanService::class))->newInstanceWithoutConstructor(),
        );

        $reflection = new ReflectionClass(AgentToolTurnProcessor::class);
        /** @var AgentToolTurnProcessor $processor */
        $processor = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'toolPlanResolver' => $resolver,
            'auditLogger' => new AgentAuditLogger($repository),
            'editorLabelService' => new AgentToolEditorLabelService($translator),
            'translator' => $translator,
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($processor, $value);
        }

        return $processor;
    }
}
