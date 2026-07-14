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

namespace NITSAN\NsT3AF\Api;

/**
 * Result of a text-to-speech call via {@see TtsServiceInterface::speak()}.
 *
 * {@see $audio} is raw binary audio data — write directly to a file or stream
 * to the browser with the appropriate `Content-Type: {$mimeType}` header.
 *
 * @api
 */
final readonly class TtsResponse
{
    /**
     * @param string            $audio              Raw binary audio payload.
     * @param string            $mimeType           MIME type matching the requested format (e.g. `audio/mpeg` for mp3).
     * @param string            $modelId            Model that produced the audio.
     * @param string            $providerIdentifier Identifier of the provider record used.
     * @param int               $latencyMs          Round-trip time in milliseconds.
     * @param int               $tokensInput        Input/billable tokens (T3Planet Credits only; 0 for BYO TTS).
     * @param int               $tokensTotal        Total tokens billed (T3Planet Credits only; 0 for BYO TTS).
     * @param CreditsUsage|null $credits            Credit debit snapshot when routed through T3Planet Credits.
     */
    public function __construct(
        public string $audio,
        public string $mimeType,
        public string $modelId,
        public string $providerIdentifier,
        public int $latencyMs = 0,
        public int $tokensInput = 0,
        public int $tokensTotal = 0,
        public ?CreditsUsage $credits = null,
    ) {}
}
