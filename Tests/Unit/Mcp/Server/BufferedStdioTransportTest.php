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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Server;

use NITSAN\NsT3AF\Mcp\Server\BufferedStdioTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A big message reaches the pipe in pieces. It must arrive as ONE message, once its newline is there.
 *
 * @internal
 */
final class BufferedStdioTransportTest extends TestCase
{
    /** @var resource */
    private $writer;

    /** @var list<string> */
    private array $messages = [];

    private BufferedStdioTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$reader, $this->writer] = $pair;
        stream_set_blocking($reader, false);

        $output = fopen('php://memory', 'wb');
        self::assertIsResource($output);

        $this->transport = new BufferedStdioTransport($reader, $output, null, 100);
        $this->transport->onMessage(function ($transport, string $payload): void {
            $this->messages[] = $payload;
        });
    }

    #[Test]
    public function aMessageInTwoPiecesIsDeliveredOnceAndWhole(): void
    {
        $message = '{"jsonrpc":"2.0","id":1,"params":"' . str_repeat('a', 60) . '"}';

        fwrite($this->writer, substr($message, 0, 40));
        $this->process();
        self::assertSame([], $this->messages);

        fwrite($this->writer, substr($message, 40) . "\n");
        $this->process();

        self::assertSame([$message], $this->messages);
    }

    #[Test]
    public function twoMessagesInOneReadAreDeliveredSeparately(): void
    {
        fwrite($this->writer, "{\"a\":1}\n{\"b\":2}\n");
        $this->process();

        self::assertSame(['{"a":1}', '{"b":2}'], $this->messages);
    }

    #[Test]
    public function aLineOverTheLimitIsDroppedAndTheNextMessageStillArrives(): void
    {
        fwrite($this->writer, str_repeat('x', 150));
        $this->process();
        fwrite($this->writer, "yy\n{\"ok\":1}\n");
        $this->process();

        self::assertSame(['{"ok":1}'], $this->messages);
    }

    private function process(): void
    {
        (new \ReflectionMethod($this->transport, 'processInput'))->invoke($this->transport);
    }
}
