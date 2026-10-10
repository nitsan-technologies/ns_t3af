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

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Agent\Service\AgentToolEditorLabelService;
use NITSAN\NsT3AF\Agent\Service\AgentToolPlanResolver;
use NITSAN\NsT3AF\Agent\Service\AgentToolTurnProcessor;
use NITSAN\NsT3AF\Agent\Service\DynamicToolPlanService;
use NITSAN\NsT3AF\Agent\Service\SatelliteToolPlanService;
use NITSAN\NsT3AF\Mcp\Contract\McpArgumentCheckInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Exception\NoChangeRequiredException;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
final class AgentToolTurnProcessorUnchangedGlossaryTest extends TestCase
{
    use AgentTranslatorTrait;

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function anUnchangedGlossaryPairIsToldWithoutACard(): void
    {
        $translator = $this->createAgentTranslator();
        $resolver = new AgentToolPlanResolver(
            [new UnchangedGlossaryProbeTool()],
            (new ReflectionClass(DynamicToolPlanService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SatelliteToolPlanService::class))->newInstanceWithoutConstructor(),
        );
        $reflection = new ReflectionClass(AgentToolTurnProcessor::class);
        /** @var AgentToolTurnProcessor $processor */
        $processor = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('toolPlanResolver')->setValue($processor, $resolver);
        $reflection->getProperty('editorLabelService')->setValue($processor, new AgentToolEditorLabelService($translator));
        $reflection->getProperty('translator')->setValue($processor, $translator);

        $method = $reflection->getMethod('processWriteToolTurn');
        /** @var array{role: string, content: string, meta: array<string, mixed>} $message */
        $message = $method->invoke(
            $processor,
            ['name' => 't3ai_glossary_save', 'executable' => true, 'severity' => ToolSeverity::Write->value],
            [],
            ['arguments' => ['sourceTerm' => 'Test 123', 'targetTerm' => 'Agent 123', 'languageUid' => 2]],
            ToolSeverity::Write->value,
            'corr-glossary-1',
        );

        self::assertSame('info', $message['meta']['type'] ?? null);
        self::assertTrue($message['meta']['orchestratorPause'] ?? false);
        self::assertArrayNotHasKey('draft', $message['meta']);
        self::assertSame(
            '"Test 123" is already in the word list as "Agent 123" (French).',
            $message['content'],
        );
    }
}

/**
 * @internal
 */
final class UnchangedGlossaryProbeTool implements McpArgumentCheckInterface, McpPlannableToolInterface
{
    public function plan(array $arguments): ToolPlan
    {
        throw new \LogicException('A card must not be built.');
    }

    #[McpTool(name: 't3ai_glossary_save', description: 'probe')]
    public function execute(): string
    {
        return '';
    }

    public function checkArguments(array $arguments): void
    {
        throw new NoChangeRequiredException('"Test 123" is already in the word list as "Agent 123" (French).');
    }
}
