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
 * Result of an embedding call.
 *
 * Returned by {@see AiServiceInterface::embed()}. Each input string maps to one
 * vector entry in {@see self::$vectors}, in the same order as the input.
 *
 * @api
 */
final readonly class EmbeddingResponse
{
    /**
     * @param list<list<float>>    $vectors            One entry per input; each is a dense vector of floats.
     * @param string               $modelId            Embedding model used.
     * @param string               $providerIdentifier Identifier of the provider record used.
     * @param int                  $tokensInput        Prompt token count, when reported.
     * @param int                  $latencyMs          Round-trip time in milliseconds.
     * @param array<string, mixed> $raw                Adapter-specific response payload.
     * @param CreditsUsage|null    $credits            Set when T3Planet Credits mode handled the request.
     */
    public function __construct(
        public array $vectors,
        public string $modelId,
        public string $providerIdentifier,
        public int $tokensInput = 0,
        public int $latencyMs = 0,
        public array $raw = [],
        public ?CreditsUsage $credits = null,
    ) {}
}
