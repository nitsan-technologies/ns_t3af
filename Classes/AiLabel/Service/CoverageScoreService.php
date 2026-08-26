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

namespace NITSAN\NsT3AF\AiLabel\Service;

/**
 * Internal coverage checklist score with published blind spots.
 */
final class CoverageScoreService
{
    /**
     * @return array{score: int, checks: list<array<string, mixed>>, blindSpots: list<string>}
     */
    public function compute(
        OriginRecorder $originRecorder,
        EuIconManifestService $iconManifest,
    ): array {
        $checks = [];
        $passed = 0;

        $iconsOk = $iconManifest->verify();
        $checks[] = ['id' => 'eu_icons', 'label' => 'EU icon pack verified', 'passed' => $iconsOk];
        if ($iconsOk) {
            ++$passed;
        }

        $unbound = $originRecorder->listUnboundGenerations();
        $unboundOk = $unbound === [];
        $checks[] = [
            'id' => 'unbound_generations',
            'label' => 'No unbound AI generations',
            'passed' => $unboundOk,
            'count' => count($unbound),
        ];
        if ($unboundOk) {
            ++$passed;
        }

        $blindSpots = [
            'Content generated outside AI Universe',
            'Custom theme templates without fluid_styled_content DropIn',
            'Whether content legally requires a label',
            'Whether the customer review process meets the legal standard',
        ];

        $total = count($checks);
        $score = $total > 0 ? (int) round(($passed / $total) * 100) : 0;

        return [
            'score' => $score,
            'checks' => $checks,
            'blindSpots' => $blindSpots,
        ];
    }
}
