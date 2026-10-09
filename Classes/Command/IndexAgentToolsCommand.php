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

use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 't3af:agent:index-tools',
    description: 'Rebuild / warm the AI Agent semantic tool embedding index',
)]
final class IndexAgentToolsCommand extends Command
{
    public function __construct(
        private readonly AgentToolIndexInterface $toolIndex,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->toolIndex->rebuild();
            $io->success('Agent tool embedding index rebuilt.');

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error('Failed to rebuild agent tool index: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }
}
