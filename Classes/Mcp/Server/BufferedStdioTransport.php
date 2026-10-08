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

namespace NITSAN\NsT3AF\Mcp\Server;

use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;

/**
 * The stdio transport of the MCP SDK, with line reading that survives big messages.
 *
 * The SDK puts STDIN in non-blocking mode and takes whatever fgets() returns as one complete message. A pipe holds
 * about 64 KB, so a bigger message arrives in pieces: the first piece (no newline yet) is parsed as a message, fails,
 * and the rest is parsed as a second message and fails too ("Parse error" twice, no request id, no answer).
 * This transport collects the pieces and hands over a message only when its newline has arrived.
 */
class BufferedStdioTransport extends StdioTransport
{
    /**
     * A line longer than this is dropped. records_apply takes 2 MB of arguments, which JSON escaping can roughly
     * double, so this leaves room and still protects the process from a peer that never sends a newline.
     */
    public const DEFAULT_MAX_MESSAGE_BYTES = 8 * 1024 * 1024;

    private const READ_CHUNK_BYTES = 65536;

    private string $buffer = '';

    /** True while the rest of a line that was too long is still coming in, to be thrown away. */
    private bool $discarding = false;

    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(
        private $stdin = \STDIN,
        $stdout = \STDOUT,
        ?LoggerInterface $logger = null,
        private readonly int $maxMessageBytes = self::DEFAULT_MAX_MESSAGE_BYTES,
    ) {
        parent::__construct($stdin, $stdout, $logger);
    }

    protected function processInput(): void
    {
        $chunk = fread($this->stdin, self::READ_CHUNK_BYTES);
        if ($chunk === false || $chunk === '') {
            if (feof($this->stdin) && $this->buffer !== '') {
                // The client closed the pipe after a last message without a newline.
                $last = trim($this->buffer);
                $this->buffer = '';
                if ($last !== '' && !$this->discarding) {
                    $this->handleMessage($last, $this->sessionId);
                }

                return;
            }

            usleep(50000);

            return;
        }

        $this->buffer .= $chunk;

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);

            if ($this->discarding) {
                // That was the end of the line that was too long.
                $this->discarding = false;

                continue;
            }

            $line = trim($line);
            if ($line !== '') {
                $this->handleMessage($line, $this->sessionId);
            }
        }

        if (strlen($this->buffer) > $this->maxMessageBytes) {
            $this->buffer = '';
            if (!$this->discarding) {
                $this->discarding = true;
                $this->logger->warning('BufferedStdioTransport discarded an input line exceeding the maximum length.', [
                    'max_message_bytes' => $this->maxMessageBytes,
                ]);
            }
        }
    }
}
