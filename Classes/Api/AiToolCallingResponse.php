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

namespace NITSAN\NsT3AF\Api;

/**
 * Result of a tool-calling completion turn.
 *
 * @api
 */
final readonly class AiToolCallingResponse
{
    /**
     * @param list<AiToolCall> $toolCalls Empty when the model returned assistant text only.
     * @param array<string, mixed> $raw Adapter-specific payload for debugging.
     */
    public function __construct(
        public string $content,
        public string $modelId,
        public string $providerIdentifier,
        public array $toolCalls = [],
        public int $tokensInput = 0,
        public int $tokensOutput = 0,
        public int $latencyMs = 0,
        public array $raw = [],
        public ?int $appliedBrandContextProfileUid = null,
    ) {}
}
