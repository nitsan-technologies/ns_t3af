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

use NITSAN\NsT3AF\Api\TtsResponse;

/**
 * Dispatched after a successful TTS adapter call returns.
 *
 * Listeners may record usage metrics or audit the request.
 * The response is read-only — the audio binary cannot be mutated post-hoc.
 *
 * @api
 */
final class AfterTtsResponseEvent
{
    public function __construct(
        public readonly TtsResponse $response,
        public readonly string $text,
    ) {}
}
