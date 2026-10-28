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

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Shared CLI presentation for ns_t3af commands: SymfonyStyle on the command output,
 * with plain-text mirror on STDERR when TYPO3 Scheduler runs the command (NullOutput).
 */
final class CommandIo
{
    private SymfonyStyle $style;

    private OutputInterface $output;

    private ?OutputInterface $schedulerMirror = null;

    public function __construct(
        InputInterface $input,
        OutputInterface $output,
        /**
         * @internal PHPUnit only — when set, used instead of STDERR for NullOutput mirroring.
         */
        private readonly ?OutputInterface $schedulerMirrorOverride = null,
    ) {
        $this->output = $output;
        $this->style = new SymfonyStyle($input, $output);
    }

    public function title(string $message): void
    {
        $this->style->title($message);
        $this->mirrorPlain($message);
    }

    public function section(string $message): void
    {
        $this->style->section($message);
        $this->mirrorPlain($message);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $this->style->table($headers, $rows);
        if (!$this->shouldMirror()) {
            return;
        }
        $buffer = new BufferedOutput();
        (new Table($buffer))->setHeaders($headers)->setRows($rows)->render();
        $content = $buffer->fetch();
        if ($content !== '') {
            $this->schedulerMirror()->write($content);
        }
    }

    public function writeln(string $message = ''): void
    {
        $this->style->writeln($message);
        $this->mirrorPlain(self::stripConsoleTags($message));
    }

    public function line(string $message): void
    {
        $this->writeln($message);
    }

    public function success(string $message): void
    {
        $this->style->success($message);
        $this->mirrorPlain('✓ ' . $message);
    }

    public function error(string $message): void
    {
        $this->style->error($message);
        $this->mirrorPlain('✗ ' . $message);
    }

    public function warning(string $message): void
    {
        $this->style->warning($message);
        $this->mirrorPlain('⚠ ' . $message);
    }

    private function shouldMirror(): bool
    {
        return $this->output instanceof NullOutput;
    }

    private function mirrorPlain(string $message): void
    {
        if (!$this->shouldMirror() || $message === '') {
            return;
        }
        $this->schedulerMirror()->writeln($message);
    }

    private function schedulerMirror(): OutputInterface
    {
        if ($this->schedulerMirrorOverride !== null) {
            return $this->schedulerMirrorOverride;
        }

        if ($this->schedulerMirror !== null) {
            return $this->schedulerMirror;
        }

        if (Environment::isCli() && defined('STDERR')) {
            $this->schedulerMirror = new StreamOutput(
                \STDERR,
                OutputInterface::VERBOSITY_NORMAL,
                true,
            );
        } else {
            $this->schedulerMirror = new NullOutput();
        }

        return $this->schedulerMirror;
    }

    private static function stripConsoleTags(string $message): string
    {
        $plain = preg_replace('/<[^>]*>/', '', $message);

        return is_string($plain) ? trim($plain) : trim($message);
    }
}
