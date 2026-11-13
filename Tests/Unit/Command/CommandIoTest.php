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

namespace NITSAN\NsT3AF\Tests\Unit\Command;

use NITSAN\NsT3AF\Command\CommandIo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 */
final class CommandIoTest extends TestCase
{
    #[Test]
    public function interactiveOutputIncludesSuccessMessage(): void
    {
        $output = new BufferedOutput();
        $io = new CommandIo(new ArrayInput([]), $output);
        $io->success('Cleanup completed.');

        self::assertStringContainsString('Cleanup completed', $output->fetch());
    }

    #[Test]
    public function nullOutputMirrorsPlainTextToInjectedSink(): void
    {
        $sink = new BufferedOutput();
        $io = new CommandIo(new ArrayInput([]), new NullOutput(), $sink);
        $io->line('Deleted 3 expired OAuth tokens.');
        $io->success('Cleanup completed.');

        $mirrored = $sink->fetch();
        self::assertStringContainsString('Deleted 3 expired OAuth tokens.', $mirrored);
        self::assertStringContainsString('✓ Cleanup completed.', $mirrored);
    }

    #[Test]
    public function writelnStripsConsoleTagsForMirror(): void
    {
        $sink = new BufferedOutput();
        $io = new CommandIo(new ArrayInput([]), new NullOutput(), $sink);
        $io->writeln('<info>PASS</info> scenario-01');

        self::assertStringContainsString('PASS scenario-01', $sink->fetch());
    }
}
