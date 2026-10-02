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

namespace NITSAN\NsT3AF\Command;

use NITSAN\NsT3AF\Agent\Context\AgentContextPresenter;
use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Eval\AgentEvalCliEnvironment;
use NITSAN\NsT3AF\Agent\Eval\AgentScenarioRunner;
use NITSAN\NsT3AF\Agent\Service\AgentEvalRunner;
use NITSAN\NsT3AF\Agent\Service\AgentProviderOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[AsCommand(
    name: 't3af:agent:eval',
    description: 'AI Agent eval: --live runs the scenarios (create page, translate, SEO, delete, …) against providers; without it, replays routing fixtures',
)]
final class EvalAgentCommand extends Command
{
    public function __construct(
        private readonly AgentEvalRunner $evalRunner,
        private readonly AgentScenarioRunner $scenarioRunner,
        private readonly AgentEvalCliEnvironment $cliEnvironment,
        private readonly AgentProviderOptions $providerOptions,
        private readonly AgentContextPresenter $contextPresenter,
        private readonly AgentToolIndexInterface $toolIndex,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('record', null, InputOption::VALUE_NONE, 'Write a fixture from canned mocked outputs (no LLM)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Fixture id (required with --record)')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'User message stored on the fixture', '')
            ->addOption('tool', null, InputOption::VALUE_REQUIRED, 'Mocked tool name', '')
            ->addOption('field-keys', null, InputOption::VALUE_REQUIRED, 'Comma-separated fieldKeys', '')
            ->addOption('routing-source', null, InputOption::VALUE_REQUIRED, 'Mocked routingSource', 'embeddings')
            ->addOption('variants-complete', null, InputOption::VALUE_REQUIRED, 'true|false for variantsComplete', 'true')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Fixture directory (default Tests/Fixtures/AgentEval)')
            ->addOption('live', null, InputOption::VALUE_NONE, 'Run the scenarios through the real agent with real providers (costs tokens / credits; nothing is written)')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider identifiers, comma-separated, or "all" (with --live)', 'default')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page uid the scenarios run on (with --live)', '0')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'Only these scenarios: ids or number prefixes, comma-separated, e.g. "04,05" (with --live)', '')
            ->addOption('scenario-dir', null, InputOption::VALUE_REQUIRED, 'Scenario directory (default Resources/Private/Agent/Eval/Scenarios)', '')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write the full result as JSON to this file (with --live)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getOption('dir');
        $directory = is_string($directory) && $directory !== ''
            ? $directory
            : $this->evalRunner->defaultFixtureDirectory();

        try {
            if ($input->getOption('live') === true) {
                return $this->live($input, $io);
            }
            if ($input->getOption('record') === true) {
                return $this->record($input, $io, $directory);
            }

            return $this->replay($io, $directory);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function record(InputInterface $input, SymfonyStyle $io, string $directory): int
    {
        $id = trim((string) $input->getOption('id'));
        if ($id === '') {
            $io->error('--id is required with --record.');

            return Command::FAILURE;
        }

        $fieldKeysRaw = trim((string) $input->getOption('field-keys'));
        $fieldKeys = $fieldKeysRaw === ''
            ? []
            : array_values(array_filter(array_map(static fn(string $part): string => trim($part), explode(',', $fieldKeysRaw))));

        $mocked = [
            'tool' => trim((string) $input->getOption('tool')),
            'routingSource' => trim((string) $input->getOption('routing-source')),
            'fieldKeys' => $fieldKeys,
            'arguments' => [
                'fieldKeys' => $fieldKeys,
            ],
            'variantsComplete' => filter_var((string) $input->getOption('variants-complete'), FILTER_VALIDATE_BOOLEAN),
        ];

        $path = $this->evalRunner->record([
            'id' => $id,
            'userMessage' => (string) $input->getOption('message'),
            'mocked' => $mocked,
            'expect' => $this->evalRunner->expectFromMocked($mocked),
        ], $directory);

        $io->success('Recorded eval fixture: ' . $path);

        return Command::SUCCESS;
    }

    private function live(InputInterface $input, SymfonyStyle $io): int
    {
        $pageId = (int) $input->getOption('page');
        if ($pageId <= 0) {
            $io->error('--page=<uid> is required: the scenarios run on that page (for example an "Eval" page). Nothing is written to it.');

            return Command::FAILURE;
        }
        $scenarioDir = trim((string) $input->getOption('scenario-dir'));
        $only = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('scenario')))));
        $scenarios = $this->scenarioRunner->load($scenarioDir !== '' ? $scenarioDir : null, $only);
        if ($scenarios === []) {
            $io->warning('No scenarios found.');

            return Command::FAILURE;
        }

        $user = $this->cliEnvironment->start();
        try {
            $providers = $this->providers((string) $input->getOption('provider'), $pageId, $user);
            $io->title(sprintf('AI Agent eval: %d scenario(s) × %d provider(s) on page %d', count($scenarios), count($providers), $pageId));

            // The tool index (embeddings) is built once after a cache flush; do it before the
            // first scenario so its time is not counted against a turn.
            $started = microtime(true);
            try {
                $this->toolIndex->ensureFresh();
                $io->writeln(sprintf('<fg=gray>Tool index ready (%.1f s).</>', microtime(true) - $started));
            } catch (\Throwable $exception) {
                $io->writeln('<comment>Tool index could not be prepared: ' . $exception->getMessage() . '</comment>');
            }

            $reports = [];
            $rows = [];
            foreach ($providers as $provider) {
                $io->section('Provider: ' . $provider);
                foreach ($scenarios as $scenario) {
                    $context = $this->contextPresenter->present([
                        'pageId' => $pageId,
                        'module' => AgentScenarioRunner::moduleOf($scenario),
                        'record' => null,
                        'languageId' => 0,
                        'workspaceId' => (int) $user->workspace,
                    ], $user);
                    $report = $this->scenarioRunner->run($scenario, $provider, $context, $user);
                    $reports[] = $report;
                    $this->printScenario($io, $report);
                    $rows[] = [$provider, $report['id'], strtoupper($report['status']), $report['seconds'] . ' s'];
                }
            }
        } finally {
            $this->cliEnvironment->end();
        }

        $io->section('Summary');
        $io->table(['Provider', 'Scenario', 'Result', 'Time'], $rows);

        $reportPath = trim((string) $input->getOption('report'));
        if ($reportPath !== '') {
            file_put_contents($reportPath, json_encode(
                ['createdAt' => date('c'), 'pageId' => $pageId, 'results' => $reports],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
            ) . "\n");
            $io->writeln('Report: ' . $reportPath);
        }

        $failed = count(array_filter($reports, static fn(array $report): bool => $report['status'] === 'fail'));
        if ($failed > 0) {
            $io->error(sprintf('%d of %d run(s) failed.', $failed, count($reports)));

            return Command::FAILURE;
        }
        $io->success(sprintf('%d run(s) passed (warnings and skips included).', count($reports)));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function providers(string $option, int $pageId, BackendUserAuthentication $user): array
    {
        if (trim($option) === 'all') {
            return array_values(array_unique(array_map(
                static fn(array $entry): string => $entry['value'],
                $this->providerOptions->options($pageId, $user),
            )));
        }
        $providers = array_values(array_filter(array_map('trim', explode(',', $option))));

        return $providers !== [] ? $providers : [AgentProviderOptions::DEFAULT];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function printScenario(SymfonyStyle $io, array $report): void
    {
        $tag = match ($report['status']) {
            'pass' => '<info>PASS</info>',
            'warn' => '<comment>WARN</comment>',
            'skip' => '<comment>SKIP</comment>',
            default => '<error>FAIL</error>',
        };
        $io->writeln(sprintf('%s %s  <fg=gray>%s · %s s</>', $tag, $report['id'], $report['title'], $report['seconds']));
        foreach ($report['turns'] as $turn) {
            if (($turn['status'] ?? '') === 'skip') {
                continue;
            }
            $io->writeln(sprintf(
                '     %s: %s → %s%s',
                $turn['label'],
                mb_strimwidth((string) $turn['message'], 0, 60, '…'),
                ($turn['toolCalls'] ?? []) !== [] ? implode(', ', $turn['toolCalls']) : 'no tools',
                ($turn['cards'] ?? []) !== [] ? ' · ' . implode(', ', $turn['cards']) : '',
            ));
        }
        foreach ([...$report['errors'], ...$report['warnings']] as $note) {
            $io->writeln('     - ' . $note);
        }
        if ($report['status'] === 'fail' || $report['status'] === 'warn') {
            // What the agent answered instead, so the failure can be understood without the report file.
            foreach ($report['turns'] as $turn) {
                $reply = trim((string) ($turn['reply'] ?? ''));
                if (($turn['status'] ?? '') !== 'pass' && $reply !== '') {
                    $io->writeln(sprintf('     <fg=gray>%s replied: "%s"</>', $turn['label'], mb_strimwidth(preg_replace('/\s+/', ' ', $reply) ?? $reply, 0, 200, '…')));
                }
                // What the agent sent, so a wrong field name is visible without the report file.
                if (($turn['status'] ?? '') !== 'pass') {
                    foreach (is_array($turn['arguments'] ?? null) ? $turn['arguments'] : [] as $call) {
                        $arguments = json_encode($call['arguments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
                        $io->writeln(sprintf('     <fg=gray>%s sent %s: %s</>', $turn['label'], (string) ($call['tool'] ?? ''), mb_strimwidth($arguments, 0, 300, '…')));
                    }
                }
            }
        }
    }

    private function replay(SymfonyStyle $io, string $directory): int
    {
        $results = $this->evalRunner->replay($directory);
        if ($results === []) {
            $io->warning('No eval fixtures found in ' . $directory);

            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($results as $result) {
            if ($result['ok']) {
                $io->writeln(sprintf('<info>PASS</info> %s', $result['id']));
                continue;
            }
            ++$failed;
            $io->writeln(sprintf('<error>FAIL</error> %s', $result['id']));
            foreach ($result['errors'] as $error) {
                $io->writeln('  - ' . $error);
            }
        }

        if ($failed > 0) {
            $io->error(sprintf('%d of %d fixture(s) failed.', $failed, count($results)));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d fixture(s) passed.', count($results)));

        return Command::SUCCESS;
    }
}
