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

namespace NITSAN\NsT3AF\Mcp\Attribute;

use Attribute;

/**
 * Retrieval metadata for agent tool shortlisting (embeddings + category pick).
 *
 * `$verbs` / `$nouns` are deprecated for scoring — kept for BC and folded into
 * the embedded text. Prefer `$summary`, `$examples`, and `$category`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class McpToolIntent
{
    /**
     * @param list<string> $verbs      @deprecated Kept for BC; folded into embeddings, not scored
     * @param list<string> $nouns      @deprecated Kept for BC; folded into embeddings, not scored
     * @param list<string> $modules    Backend module hints, e.g. records, web_layout, file
     * @param list<string> $examples   Short example requests (mixed languages) for embeddings only
     * @param string       $summary    One-line English summary for the LLM / embeddings
     * @param string       $category   content|pages|seo|media_files|translation|…|general
     */
    public function __construct(
        public readonly array $verbs = [],
        public readonly array $nouns = [],
        public readonly array $modules = [],
        public readonly bool $requiresPage = false,
        public readonly string $summary = '',
        public readonly array $examples = [],
        public readonly string $category = '',
    ) {}
}
