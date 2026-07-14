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

namespace NITSAN\NsT3AF\Provider\Contract;

/**
 * Result of an {@see AdapterInterface::testConnection()} probe.
 *
 * Adapters MUST NOT throw from `testConnection()` — they wrap any failure here
 * via {@see self::failure()}. The dashboard persists the outcome to the
 * provider row's `last_status*` columns.
 *
 * @api Returned by every adapter; field names are part of the semver-stable
 *      contract.
 */
final readonly class VerifyResult
{
    /**
     * @param bool         $ok        Whether the probe succeeded.
     * @param string|null  $message   Human-readable error or info message; null when nothing to surface.
     * @param list<string> $models    Optional list of models the bridge reported back.
     * @param int          $latencyMs Round-trip time in milliseconds.
     */
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public array $models = [],
        public int $latencyMs = 0,
    ) {}

    /**
     * @param list<string> $models
     */
    public static function ok(?string $message = null, array $models = [], int $latencyMs = 0): self
    {
        return new self(true, $message, $models, $latencyMs);
    }

    public static function failure(string $message, int $latencyMs = 0): self
    {
        return new self(false, $message, [], $latencyMs);
    }
}
