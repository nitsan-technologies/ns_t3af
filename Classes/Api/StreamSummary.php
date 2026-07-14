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
 * Final frame of a streamed AI call when T3Planet Credits mode is active.
 *
 * Obtain via `$generator->getReturn()` after consuming all yielded chunks.
 *
 * @api
 */
final readonly class StreamSummary
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $content,
        public ?CreditsUsage $credits = null,
        public array $raw = [],
    ) {}
}
