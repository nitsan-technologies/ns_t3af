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
use NITSAN\NsT3AF\Agent\Service\GlossarySaveLanguage;
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
final class GlossarySaveLanguageTest extends TestCase
{
    use AgentTranslatorTrait;

    /**
     * @var list<array{id: int, title: string}>
     */
    private const LANGUAGES = [
        ['id' => 0, 'title' => 'English'],
        ['id' => 1, 'title' => 'German'],
        ['id' => 2, 'title' => 'French'],
        ['id' => 3, 'title' => 'Hindi'],
    ];

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function anUnnamedLanguageAsksInsteadOfPickingGerman(): void
    {
        $choice = GlossarySaveLanguage::resolve(
            'Save a glossary term: "Layout" should be translated as "Layoutansicht".',
            self::LANGUAGES,
        );

        self::assertSame(['German', 'French', 'Hindi'], $choice['options'] ?? null);
        self::assertArrayNotHasKey('languageUid', $choice);
    }

    #[Test]
    public function aNamedLanguageIsUsedEvenWhenTheModelSentGerman(): void
    {
        $choice = GlossarySaveLanguage::resolve(
            'Remember that in French we say Agent XYZ not Test 123.',
            self::LANGUAGES,
        );

        self::assertSame(2, $choice['languageUid'] ?? null);
    }

    #[Test]
    public function oneOtherSiteLanguageIsLeftAlone(): void
    {
        self::assertSame([], GlossarySaveLanguage::resolve('Save Layout as Layoutansicht.', [
            ['id' => 0, 'title' => 'English'],
            ['id' => 1, 'title' => 'German'],
        ]));
    }

    #[Test]
    public function aGlossarySaveWithoutANamedLanguageDoesNotOpenACard(): void
    {
        $translator = $this->createAgentTranslator();
        $probe = new GlossaryLanguageProbeTool();
        $resolver = new AgentToolPlanResolver(
            [$probe],
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
            ['details' => ['siteLanguages' => self::LANGUAGES]],
            [
                'arguments' => ['sourceTerm' => 'Layout', 'targetTerm' => 'Layoutansicht', 'languageUid' => 1],
                'requestQuery' => 'Save a glossary term: "Layout" should be translated as "Layoutansicht".',
            ],
            ToolSeverity::Write->value,
            'corr-glossary-lang',
        );

        self::assertSame('clarification', $message['meta']['type'] ?? null);
        self::assertTrue($message['meta']['orchestratorPause'] ?? false);
        self::assertArrayNotHasKey('draft', $message['meta']);
        self::assertSame(['German', 'French', 'Hindi'], $message['meta']['options'] ?? null);
        self::assertSame('Which language should I save this word in?', $message['content']);
        self::assertNull($probe->languageUid);
    }

    #[Test]
    public function aNamedLanguageReplacesTheModelLanguageBeforeTheCard(): void
    {
        $translator = $this->createAgentTranslator();
        $probe = new GlossaryLanguageProbeTool();
        $resolver = new AgentToolPlanResolver(
            [$probe],
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
        $method->invoke(
            $processor,
            ['name' => 't3ai_glossary_save', 'executable' => true, 'severity' => ToolSeverity::Write->value],
            ['details' => ['siteLanguages' => self::LANGUAGES]],
            [
                'arguments' => ['sourceTerm' => 'Test 123', 'targetTerm' => 'Agent XYZ', 'languageUid' => 1],
                'requestQuery' => 'Remember that in French we say Agent XYZ not Test 123.',
            ],
            ToolSeverity::Write->value,
            'corr-glossary-lang-2',
        );

        self::assertSame(2, $probe->languageUid);
    }
}

/**
 * @internal
 */
final class GlossaryLanguageProbeTool implements McpArgumentCheckInterface, McpPlannableToolInterface
{
    public ?int $languageUid = null;

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
        $this->languageUid = (int) ($arguments['languageUid'] ?? 0);
        throw new NoChangeRequiredException('checked');
    }
}
