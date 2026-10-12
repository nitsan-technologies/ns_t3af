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

namespace NITSAN\NsT3AF\Agent\Service;

/**
 * Source word and translation written as "we say B not A" or "A -> B".
 *
 * @internal
 */
final class GlossaryPairRequest
{
    /**
     * @return array{source: string, target: string}|null
     */
    public static function parse(string $message): ?array
    {
        $message = trim($message);
        if ($message === '' || str_starts_with($message, '[The editor ')) {
            return null;
        }

        $said = self::saidNot($message);
        if ($said !== null) {
            return $said;
        }
        if (preg_match('/\b(?:glossary|word\s+list|glossar|wortliste)\b/iu', $message) !== 1) {
            return null;
        }

        return self::arrow($message);
    }

    /**
     * "we say Layoutansicht not Layout" → source Layout, translation Layoutansicht.
     *
     * @return array{source: string, target: string}|null
     */
    private static function saidNot(string $message): ?array
    {
        if (preg_match('/\bwe\s+say\s+(.+?)\s+not\s+(.+?)(?:[.!?]|$)/iu', $message, $match) !== 1
            && preg_match('/\bsagen(?:\s+wir)?\s+(.+?)\s+nicht\s+(.+?)(?:[.!?]|$)/iu', $message, $match) !== 1
        ) {
            return null;
        }

        $target = self::clean((string) $match[1]);
        $source = self::clean((string) $match[2]);
        if ($source === '' || $target === '' || $source === $target) {
            return null;
        }

        return ['source' => $source, 'target' => $target];
    }

    /**
     * @return array{source: string, target: string}|null
     */
    private static function arrow(string $message): ?array
    {
        if (preg_match('/(.+?)\s*(?:→|->|=>)\s*(.+)/u', $message, $match) !== 1) {
            return null;
        }

        $source = self::clean(self::stripLead((string) $match[1]));
        $target = self::clean(self::stripTail((string) $match[2]));
        if ($source === '' || $target === '' || $source === $target) {
            return null;
        }

        return ['source' => $source, 'target' => $target];
    }

    private static function stripLead(string $value): string
    {
        return preg_replace(
            '/^(?:please\s+)?(?:add|remember(?:\s+that)?|save|put|set)\s+/iu',
            '',
            trim($value),
        ) ?? trim($value);
    }

    private static function stripTail(string $value): string
    {
        $value = preg_replace(
            '/\s+(?:to|in|into|auf)\s+(?:the\s+)?(?:glossary|word\s+list|glossar|wortliste)\b.*$/iu',
            '',
            trim($value),
        ) ?? trim($value);

        return preg_replace('/\s+(?:in|auf|for)\s+\p{L}[\p{L}\s-]{0,40}$/u', '', $value) ?? $value;
    }

    private static function clean(string $value): string
    {
        return trim($value, " \t\"“”'«».");
    }
}
