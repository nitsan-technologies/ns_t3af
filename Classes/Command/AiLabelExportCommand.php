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

use NITSAN\NsT3AF\AiLabel\Service\EvidenceExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 't3af:ailabel:export', description: 'Export AI Label evidence as CSV or HTML')]
final class AiLabelExportCommand extends Command
{
    public function __construct(
        private readonly EvidenceExportService $exportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'csv or html', 'csv');
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->exportService->collectRows();
        $format = (string) $input->getOption('format');
        $payload = $format === 'html' ? $this->exportService->toHtml($rows) : $this->exportService->toCsv($rows);
        $target = (string) ($input->getOption('output') ?: 'php://stdout');
        file_put_contents($target, $payload);

        return Command::SUCCESS;
    }
}
