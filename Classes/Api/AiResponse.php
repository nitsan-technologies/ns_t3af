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
 * Result of a non-streaming AI completion call.
 *
 * Returned by {@see AiServiceInterface::complete()}. Treat all `content` text
 * as untrusted — sanitize before rendering as HTML in templates.
 *
 * @api
 */
final readonly class AiResponse
{
    /**
     * @param string                $content                         Generated text from the provider.
     * @param string                $modelId                         Model that produced this response.
     * @param string                $providerIdentifier              Identifier of the provider record used.
     * @param int                   $tokensInput                     Prompt token count, when reported.
     * @param int                   $tokensOutput                    Completion token count, when reported.
     * @param int                   $latencyMs                       Round-trip time in milliseconds.
     * @param bool                  $cached                          Reserved for a future response cache; currently always `false`.
     * @param array<string, mixed>  $raw                             Adapter-specific response payload, useful for debugging.
     * @param CreditsUsage|null     $credits                         Set when T3Planet Credits mode handled the request.
     * @param QualityScore|null     $quality                         Optional response quality assessment for telemetry/UI.
     * @param int|null              $appliedBrandContextProfileUid   Brand profile uid applied to this request, when any.
     */
    public function __construct(
        public string $content,
        public string $modelId,
        public string $providerIdentifier,
        public int $tokensInput = 0,
        public int $tokensOutput = 0,
        public int $latencyMs = 0,
        public bool $cached = false,
        public array $raw = [],
        public ?CreditsUsage $credits = null,
        public ?QualityScore $quality = null,
        public ?int $appliedBrandContextProfileUid = null,
    ) {}
}
