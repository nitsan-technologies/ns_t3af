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

/**
 * Outcome of {@see ProviderFormService::save()}.
 *
 * Either succeeds with the persisted uid or fails with a per-field error map.
 * Construct via the static factories — the constructor is package-private.
 *
 * @internal
 */
final readonly class ProviderFormResult
{
    /**
     * @param array<string, string> $errors
     */
    private function __construct(
        public bool $ok,
        public int $uid,
        public array $errors,
    ) {}

    public static function success(int $uid): self
    {
        return new self(true, $uid, []);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function errors(array $errors): self
    {
        return new self(false, 0, $errors);
    }
}
