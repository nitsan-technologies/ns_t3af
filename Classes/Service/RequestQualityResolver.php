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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\QualityScore;

/**
 * Resolves quality score payloads for request telemetry.
 *
 * @internal
 */
final class RequestQualityResolver
{
    /**
     * @return array{quality_score:int,quality_dimensions:string}
     */
    public function resolveForLog(AiResponse $response, AiOptions $options): array
    {
        $quality = $response->quality ?? $this->fromOptionsExtra($options);
        if ($quality === null) {
            return [
                'quality_score' => 0,
                'quality_dimensions' => '',
            ];
        }

        return [
            'quality_score' => $quality->score,
            'quality_dimensions' => json_encode($quality->toDimensions(), JSON_THROW_ON_ERROR),
        ];
    }

    private function fromOptionsExtra(AiOptions $options): ?QualityScore
    {
        $extra = $options->extra['quality'] ?? null;
        if (!is_array($extra)) {
            return null;
        }

        return QualityScore::fromArray($extra);
    }
}
