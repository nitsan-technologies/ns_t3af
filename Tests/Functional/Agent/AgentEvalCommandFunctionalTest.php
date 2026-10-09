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

namespace NITSAN\NsT3AF\Tests\Functional\Agent;

use NITSAN\NsT3AF\Agent\Eval\AgentEvalCliEnvironment;
use NITSAN\NsT3AF\Agent\Eval\AgentScenarioRunner;
use NITSAN\NsT3AF\Agent\Service\AgentDraftSession;
use NITSAN\NsT3AF\Command\EvalAgentCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The live eval runs on the command line: services are wired, the _cli_ user gets a session
 * for prepared changes, and the command writes a report.
 */
final class AgentEvalCommandFunctionalTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['frontend', 'workspaces', 'scheduler'];

    protected array $testExtensionsToLoad = ['ns_t3af'];

    /**
     * @var array<string, non-empty-string>
     */
    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/ns_t3af/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
    }

    #[Test]
    public function theCliUserCanKeepPreparedChanges(): void
    {
        $environment = $this->get(AgentEvalCliEnvironment::class);
        $user = $environment->start();
        try {
            $drafts = $this->get(AgentDraftSession::class);
            $drafts->storeDraft('eval-draft', ['tool' => 'write_table'], $user);

            self::assertSame(['tool' => 'write_table'], $drafts->getDraft('eval-draft', $user));
            self::assertSame('_cli_', $user->user['username'] ?? '');
        } finally {
            $environment->end();
        }
    }

    #[Test]
    public function theLiveEvalRunsAndWritesAReport(): void
    {
        $report = $this->instancePath . '/typo3temp/agent-eval.json';
        $tester = new CommandTester($this->get(EvalAgentCommand::class));
        $tester->execute(['--live' => true, '--page' => '1', '--scenario' => '01', '--report' => $report]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('01-greeting', $display, $display);
        self::assertFileExists($report);
        $decoded = json_decode((string) file_get_contents($report), true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['pageId']);
        self::assertCount(1, $decoded['results']);
        self::assertContains($decoded['results'][0]['status'], ['pass', 'warn', 'fail']);
        self::assertNotEmpty($decoded['results'][0]['turns']);
    }

    #[Test]
    public function theScenariosLoadFromTheExtension(): void
    {
        self::assertGreaterThanOrEqual(10, count($this->get(AgentScenarioRunner::class)->load()));
    }
}
