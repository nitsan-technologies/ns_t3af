<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace NITSAN\NsT3AF\Utility;

/**
 * Shared row caps for CSV/export queries (PF-05).
 */
final class ExportLimits
{
    public const MAX_ROWS = 5000;
}
