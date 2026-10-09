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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

/**
 * Collects the problems of one request, capped so a bad 500-record payload does not produce a
 * 500-line answer. Strings that came from the client are cut and stripped of control characters.
 *
 * @internal
 */
final class RecordsApplyProblems
{
    public const MAX_LISTED = 50;

    /** @var list<array<string, mixed>> */
    private array $problems = [];

    private int $omitted = 0;

    /** @param array<string, mixed> $problem */
    public function add(array $problem): void
    {
        if (count($this->problems) >= self::MAX_LISTED) {
            ++$this->omitted;

            return;
        }

        $this->problems[] = $problem;
    }

    public function isEmpty(): bool
    {
        return $this->problems === [] && $this->omitted === 0;
    }

    public function toException(): RecordsApplyValidationException
    {
        return new RecordsApplyValidationException($this->problems, $this->omitted);
    }

    /** Client-supplied text, safe to echo into an answer or a log line. */
    public static function safe(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr($clean, 0, 80);
    }
}
