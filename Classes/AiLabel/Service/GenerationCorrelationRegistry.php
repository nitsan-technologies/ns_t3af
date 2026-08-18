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
 * Request-scoped last capture id for bind sites (T4/T5).
 */
final class GenerationCorrelationRegistry
{
    private static ?string $lastCorrelationId = null;

    public static function set(string $correlationId): void
    {
        self::$lastCorrelationId = $correlationId;
    }

    public static function consume(): ?string
    {
        $id = self::$lastCorrelationId;
        self::$lastCorrelationId = null;

        return $id;
    }
}
