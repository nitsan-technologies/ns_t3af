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
 * Machine-readable reason codes for label decisions.
 */
enum ReasonCode: string
{
    case ManualExempt = 'manual_exempt';
    case PreCutoff = 'pre_cutoff';
    case Unreviewed = 'unreviewed';
    case NoAi = 'no_ai';
    case ManualForced = 'manual_forced';
    case RuleDefault = 'rule_default';
    case UnknownOrigin = 'unknown_origin';
    case NotPublicInterest = 'not_public_interest';
    case EditorialControl = 'editorial_control';
    case EditorialControlIncomplete = 'editorial_control_incomplete';
}
