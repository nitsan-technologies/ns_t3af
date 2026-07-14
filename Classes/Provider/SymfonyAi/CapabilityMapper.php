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

namespace NITSAN\NsT3AF\Provider\SymfonyAi;

use NITSAN\NsT3AF\Provider\Capability;

/**
 * Translate Symfony AI Platform `Capability` enum values into ns_t3af
 * {@see Capability} string constants.
 *
 * Symfony AI's surface is finer-grained (e.g. `input-messages` vs `input-text`)
 * than the bucketed list rendered in the dashboard, so several Symfony cases
 * collapse onto the same ns_t3af capability — duplicates are removed.
 *
 * @internal Used by {@see SymfonyAiBridgeAdapter} when reflecting a model's
 *           supported capabilities; not part of the public API.
 */
final class CapabilityMapper
{
    /**
     * Symfony AI Capability enum value → ns_t3af Capability constant.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'input-messages' => Capability::CHAT,
        'input-text' => Capability::COMPLETION,
        'input-image' => Capability::VISION,
        'input-audio' => Capability::CHAT,
        'output-streaming' => Capability::STREAMING,
        'output-structured' => Capability::TOOL_USE,
        'tool-calling' => Capability::TOOL_USE,
        'embedding' => Capability::EMBEDDINGS,
    ];

    /**
     * Map the raw Symfony AI capability identifiers (or backed-enum cases) to
     * the deduplicated ns_t3af capability list.
     *
     * @param iterable<string|\BackedEnum> $symfonyCapabilities
     * @return list<string>
     */
    public function map(iterable $symfonyCapabilities): array
    {
        $out = [];
        foreach ($symfonyCapabilities as $cap) {
            $key = $cap instanceof \BackedEnum ? (string) $cap->value : (string) $cap;
            $mapped = self::MAP[$key] ?? null;
            if ($mapped !== null && !in_array($mapped, $out, true)) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * Expose the static mapping table — useful for documentation generation
     * and architectural tests.
     *
     * @return array<string, string>
     */
    public static function table(): array
    {
        return self::MAP;
    }
}
