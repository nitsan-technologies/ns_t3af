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
 * Result of an image generation call via {@see ImageGenerationServiceInterface}.
 *
 * Each entry in {@see $images} may contain `url`, `b64_json`, and/or `revised_prompt`.
 *
 * @api
 */
final readonly class ImageGenerationResponse
{
    /**
     * @param list<array{url?: string, b64_json?: string, revised_prompt?: string}> $images
     */
    public function __construct(
        public array $images,
        public string $modelId,
        public string $providerIdentifier,
        public int $latencyMs = 0,
        public int $tokensInput = 0,
        public int $tokensTotal = 0,
        public ?CreditsUsage $credits = null,
    ) {}
}
