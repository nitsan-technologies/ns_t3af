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
 * Optional quality assessment attached to a successful AI response.
 *
 * @api
 */
final readonly class QualityScore
{
    public function __construct(
        public int $score,
        public int $relevance = 0,
        public int $readability = 0,
        public int $seoFit = 0,
        public int $brandAlignment = 0,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $score = (int) ($data['score'] ?? $data['quality_score'] ?? 0);
        if ($score <= 0) {
            return null;
        }

        return new self(
            score: min(100, max(0, $score)),
            relevance: min(100, max(0, (int) ($data['relevance'] ?? 0))),
            readability: min(100, max(0, (int) ($data['readability'] ?? 0))),
            seoFit: min(100, max(0, (int) ($data['seoFit'] ?? $data['seo_fit'] ?? 0))),
            brandAlignment: min(100, max(0, (int) ($data['brandAlignment'] ?? $data['brand_alignment'] ?? 0))),
        );
    }

    /**
     * @return array{score:int,relevance:int,readability:int,seoFit:int,brandAlignment:int}
     */
    public function toDimensions(): array
    {
        return [
            'score' => $this->score,
            'relevance' => $this->relevance,
            'readability' => $this->readability,
            'seoFit' => $this->seoFit,
            'brandAlignment' => $this->brandAlignment,
        ];
    }
}
