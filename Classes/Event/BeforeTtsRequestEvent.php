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

use NITSAN\NsT3AF\Api\TtsOptions;
use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Dispatched before a TTS adapter call is issued.
 *
 * Listeners may mutate the input text or options, or cancel the call entirely
 * (e.g. for ACL or budget gating). Cancellation returns an empty TtsResponse.
 *
 * @api
 */
final class BeforeTtsRequestEvent
{
    private string $text;

    private TtsOptions $options;

    private ?string $cancellationReason = null;

    public function __construct(
        public readonly Provider $provider,
        string $text,
        TtsOptions $options,
    ) {
        $this->text = $text;
        $this->options = $options;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    public function getOptions(): TtsOptions
    {
        return $this->options;
    }

    public function setOptions(TtsOptions $options): void
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
