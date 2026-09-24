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

namespace NITSAN\NsT3AF\Agent\Runtime;

/**
 * Mutable state of one agent turn, shared by {@see GovernedPlatform} and {@see T3afToolbox}.
 *
 * @internal
 */
final class AgentTurnState
{
    /** @var list<array{role: string, content: string, meta: array<string, mixed>}> */
    public array $messages = [];

    public int $modelRequests = 0;

    public int $readCount = 0;

    public int $writeCount = 0;

    public string $modelId = '';

    public string $providerIdentifier = '';

    /** @var list<mixed> */
    public array $trace = [];

    /** @var list<string> */
    public array $executedTools = [];

    /** @var list<string> tools added by find_tools during this turn */
    public array $foundTools = [];

    /** The provider call or the loop failed; the error message is already in {@see $messages}. */
    public bool $failed = false;

    private ?string $pauseReason = null;

    private ?string $cancelledReason = null;

    /**
     * @param callable(string, array<string, mixed>): void|null $emitEvent
     */
    public function __construct(
        public readonly string $correlationId,
        private readonly mixed $emitEvent = null,
    ) {}

    /**
     * @param array{role: string, content: string, meta: array<string, mixed>} $message
     */
    public function addMessage(array $message): void
    {
        $this->messages[] = $message;
        $this->emit('message', ['message' => $message]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload): void
    {
        if (is_callable($this->emitEvent)) {
            ($this->emitEvent)($event, $payload);
        }
    }

    public function pause(string $reason): void
    {
        $this->pauseReason ??= $reason;
    }

    public function isPaused(): bool
    {
        return $this->pauseReason !== null;
    }

    public function pauseReason(): ?string
    {
        return $this->pauseReason;
    }

    public function cancel(string $reason): void
    {
        $this->cancelledReason = $reason;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledReason !== null;
    }

    public function cancelledReason(): string
    {
        return (string) $this->cancelledReason;
    }
}
