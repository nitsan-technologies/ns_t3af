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

namespace NITSAN\NsT3AF\Event;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Dispatched right before an adapter call is issued.
 *
 * Listeners may mutate the prompt, swap the system prompt, gate the call by
 * ACL (set {@see self::cancelWithReason()} to short-circuit), or merge extra
 * adapter-specific options.
 *
 * @api
 */
final class BeforeProviderRequestEvent
{
    private string $prompt;

    private AiOptions $options;

    private ?string $cancellationReason = null;

    public function __construct(
        public readonly Provider $provider,
        string $prompt,
        AiOptions $options,
        public readonly string $callKind,
    ) {
        $this->prompt = $prompt;
        $this->options = $options;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function setPrompt(string $prompt): void
    {
        $this->prompt = $prompt;
    }

    public function getOptions(): AiOptions
    {
        return $this->options;
    }

    public function setOptions(AiOptions $options): void
    {
        $this->options = $options;
    }

    public function isCancelled(): bool
    {
        return $this->cancellationReason !== null;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function cancelWithReason(string $reason): void
    {
        $this->cancellationReason = $reason;
    }
}
