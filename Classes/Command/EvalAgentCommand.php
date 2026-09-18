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

use NITSAN\NsT3AF\Agent\Service\AgentEvalRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 't3af:agent:eval',
    description: 'Replay (or --record) AI Agent routing eval fixtures without live API keys',
)]
final class EvalAgentCommand extends Command
{
    public function __construct(
        private readonly AgentEvalRunner $evalRunner,
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
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Fixture directory (default Tests/Fixtures/AgentEval)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getOption('dir');
        $directory = is_string($directory) && $directory !== ''
            ? $directory
            : $this->evalRunner->defaultFixtureDirectory();

        try {
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
