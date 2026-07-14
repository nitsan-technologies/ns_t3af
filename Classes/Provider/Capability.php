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

namespace NITSAN\NsT3AF\Provider;

/**
 * Closed set of capabilities a provider may advertise.
 *
 * Stored on `tx_nst3af_provider.capabilities` as a comma-separated list
 * of {@see self::ALL} values. Migration to a backed enum is tracked in CLAUDE.md
 * and deferred while DB serialization stays string-based.
 *
 * @internal Public only because TCA + tests reference the constants directly.
 *           Do not depend on this class from child extensions; consume the
 *           string values instead.
 */
final class Capability
{
    public const CHAT = 'chat';
    public const COMPLETION = 'completion';
    public const EMBEDDINGS = 'embeddings';
    public const VISION = 'vision';
    public const STREAMING = 'streaming';
    public const TOOL_USE = 'tool_use';
    public const TTS = 'tts';
    public const IMAGE_GENERATION = 'image_generation';

    /** @var list<string> */
    public const ALL = [
        self::CHAT,
        self::COMPLETION,
        self::EMBEDDINGS,
        self::VISION,
        self::STREAMING,
        self::TOOL_USE,
        self::TTS,
        self::IMAGE_GENERATION,
    ];

    /**
     * Parse a stored CSV column into a normalised capability list.
     *
     * Unknown values are silently dropped; duplicates are collapsed; whitespace
     * around items is trimmed. Order from the input is preserved.
     *
     * @return list<string>
     */
    public static function fromCsv(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }
        $items = array_map('trim', explode(',', $csv));
        $items = array_values(array_filter($items, static fn(string $v): bool => in_array($v, self::ALL, true)));

        return array_values(array_unique($items));
    }

    /**
     * Serialise a capability list to its DB CSV representation.
     *
     * @param list<string> $caps
     */
    public static function toCsv(array $caps): string
    {
        $clean = array_values(array_filter($caps, static fn(string $v): bool => in_array($v, self::ALL, true)));

        return implode(',', array_values(array_unique($clean)));
    }
}
