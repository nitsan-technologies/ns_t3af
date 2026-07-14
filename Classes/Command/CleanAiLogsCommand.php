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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Command;

use NITSAN\NsT3AF\Domain\Repository\AiSysLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 't3af:ai-logs:cleanup|nst3af:ai-logs:cleanup|nst3ai:log:clear|nst3ai:clean:ailog|ns_t3ai:clean:ailog',
    description: 'Delete AI-related sys_log entries older than the retention period (--days)',
)]
final class CleanAiLogsCommand extends Command
{
    public const DEFAULT_RETENTION_DAYS = 90;

    public function __construct(
        private readonly AiSysLogRepository $aiSysLogRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Retention in days before deletion (configure when scheduling this command)',
            (string) self::DEFAULT_RETENTION_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $this->resolveRetentionDays($input);
        if ($days <= 0) {
            $io->error('Retention days must be greater than zero.');

            return Command::FAILURE;
        }

        $cutoff = time() - ($days * 86400);
        $deleted = $this->aiSysLogRepository->deleteOlderThan($cutoff);
        $io->success(sprintf(
            'Deleted %d AI log entries older than %d days (before %s).',
            $deleted,
            $days,
            date('Y-m-d H:i:s', $cutoff),
        ));

        return Command::SUCCESS;
    }

    private function resolveRetentionDays(InputInterface $input): int
    {
        $option = $input->getOption('days');
        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        return self::DEFAULT_RETENTION_DAYS;
    }
}
