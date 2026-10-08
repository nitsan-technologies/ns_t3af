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

    /** Read calls refused because the read budget was used up (the turn pauses after a few). */
    public int $budgetRefusals = 0;

    /** Reads with the same tool and arguments as an earlier read of this turn. */
    public int $repeatedReads = 0;

    /** @var array<string, string> what the model got back per read call (tool name + arguments) */
    public array $readResults = [];

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

    /** @var list<array{title: string, status: string}> the plan the model keeps up to date (update_plan) */
    public array $plan = [];

    private bool $planChanged = false;

    /**
     * Steps worked out from the editor's request. When set, the model's own plan can not swap them for
     * another list in the middle of the request (the Progress count must not jump from 3 to 2 and back).
     *
     * @var list<array{title: string, status: string}>
     */
    private array $anchorPlan = [];

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
        // The plan travels with the next assistant message, so it is saved with the conversation.
        if ($this->planChanged && ($message['role'] ?? '') === 'assistant') {
            $message['meta']['plan'] = $this->plan;
            $this->planChanged = false;
        }
        $this->messages[] = $message;
        $this->emit('message', ['message' => $message]);
    }

    /**
     * @param list<array{title: string, status: string}> $steps
     */
    public function setPlan(array $steps): void
    {
        if ($this->anchorPlan !== []) {
            // Which steps are done follows from what was applied, not from the model's own list: the
            // model's list is not allowed to replace or tick off the steps of the request.
            $steps = $this->anchorPlan;
        }
        $this->plan = $steps;
        $this->planChanged = true;
        $this->emit('plan', ['steps' => $steps]);
    }

    /**
     * Sets the plan derived from the request and keeps it as the fixed list of steps for this turn.
     *
     * @param list<array{title: string, status: string}> $steps
     */
    public function setAnchoredPlan(array $steps): void
    {
        $this->anchorPlan = $steps;
        $this->setPlan($steps);
    }

    /**
     * Saves a plan change that no later assistant message carries onto the newest one.
     */
    public function attachPendingPlan(): void
    {
        if (!$this->planChanged) {
            return;
        }
        for ($i = count($this->messages) - 1; $i >= 0; --$i) {
            if (($this->messages[$i]['role'] ?? '') === 'assistant') {
                $this->messages[$i]['meta']['plan'] = $this->plan;
                $this->planChanged = false;

                return;
            }
        }
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
