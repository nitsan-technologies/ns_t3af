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

/**
 * Shared keys + helpers for brand-context lineage (CTX-13 / CTX-15).
 *
 * The prompt-injection listener stamps the resolved profile uid onto
 * {@see AiOptions::$extra}; telemetry and {@see \NITSAN\NsT3AF\Api\AiResponse}
 * read it back so callers and the request log can audit which profile was applied.
 *
 * @internal
 */
final class BrandContextLineage
{
    public const EXTRA_PROFILE_UID = 'brandContextProfileUid';

    public static function profileUidFromOptions(AiOptions $options): ?int
    {
        $value = $options->extra[self::EXTRA_PROFILE_UID] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function stampExtra(array $extra, int $profileUid): array
    {
        if ($profileUid > 0) {
            $extra[self::EXTRA_PROFILE_UID] = $profileUid;
        }

        return $extra;
    }
}
