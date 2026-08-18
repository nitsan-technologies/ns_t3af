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

namespace NITSAN\NsT3AF\AiLabel\Domain;

/**
 * R1.1 mutually exclusive involvement values.
 */
enum Involvement: string
{
    case NotReviewed = 'not_reviewed';
    case NoAi = 'no_ai';
    case AiGenerated = 'ai_generated';
    case AiModified = 'ai_modified';
    case OriginUnknown = 'origin_unknown';
    case Suggestion = 'suggestion';

    public function isUnconfirmed(): bool
    {
        return $this === self::NotReviewed || $this === self::Suggestion;
    }
}
