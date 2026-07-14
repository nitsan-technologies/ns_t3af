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

namespace NITSAN\NsT3AF\Mcp\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use const STDERR;

/**
 * Mirrors PSR-3 log lines to STDERR (safe for MCP stdio — protocol stays on STDOUT).
 */
final class StderrLogger extends AbstractLogger
{
    /** @var array<string, int> */
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public function __construct(
        private readonly ?LoggerInterface $inner = null,
        private readonly string $minimumLevel = LogLevel::INFO,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->shouldLog($level)) {
            $line = sprintf('[mcp:%s] %s', $level, $message);
            if ($context !== []) {
                $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (defined('STDERR')) {
                fwrite(STDERR, $line . PHP_EOL);
            }
        }

        $this->inner?->log($level, $message, $context);
    }

    private function shouldLog(string $level): bool
    {
        return (self::LEVELS[$level] ?? 1) >= (self::LEVELS[$this->minimumLevel] ?? 1);
    }
}
