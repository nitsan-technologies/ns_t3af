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

namespace NITSAN\NsT3AF\Governance;

/**
 * Telemetry fidelity for a request, resolved per call.
 *
 * Stored on `tx_nst3af_provider.privacy_level` and optionally overridden
 * by the backend user's TSconfig `nst3af.privacyLevel`. When both are
 * present the {@see self::strictest()} of the two applies — a user may only
 * tighten privacy, never loosen what the provider mandates.
 *
 * @internal
 */
enum PrivacyLevel: string
{
    /** Full logging: tokens, cost, prompt fingerprint and raw metadata. */
    case Standard = 'standard';

    /** Log row written, but prompt fingerprint and raw metadata omitted. */
    case Reduced = 'reduced';

    /** No log row written at all. */
    case None = 'none';

    /**
     * Return the stricter of two levels (none > reduced > standard).
     */
    public function strictest(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    /**
     * Tolerant parse: unknown / empty values fall back to {@see self::Standard}.
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom(trim($value)) ?? self::Standard;
    }

    /**
     * Human-readable label for backend dropdowns.
     */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard (full logging)',
            self::Reduced => 'Reduced (no prompt fingerprint)',
            self::None => 'None (no logging)',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::Standard => 0,
            self::Reduced => 1,
            self::None => 2,
        };
    }
}
