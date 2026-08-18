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

use NITSAN\NsT3AF\AiLabel\Service\CoverageScoreService;
use NITSAN\NsT3AF\AiLabel\Service\EuIconManifestService;
use NITSAN\NsT3AF\AiLabel\Service\OriginRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 't3af:ailabel:audit', description: 'Report-only AI Label audit (R15, never writes labels)')]
final class AiLabelAuditCommand extends Command
{
    public function __construct(
        private readonly OriginRecorder $originRecorder,
        private readonly CoverageScoreService $coverageScoreService,
        private readonly EuIconManifestService $iconManifest,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $coverage = $this->coverageScoreService->compute($this->originRecorder, $this->iconManifest);
        $unbound = $this->originRecorder->listUnboundGenerations();

        $io->title('AI Label audit');
        $io->writeln('Coverage score: ' . $coverage['score'] . '%');
        $io->writeln('Unbound generations: ' . count($unbound));
        foreach ($coverage['blindSpots'] as $spot) {
            $io->writeln('Blind spot: ' . $spot);
        }

        return Command::SUCCESS;
    }
}
