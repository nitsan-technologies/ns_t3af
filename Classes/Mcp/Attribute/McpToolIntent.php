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
 * Retrieval metadata for the AI Agent: module tool set (modules, category) and find_tools (summary, examples).
 *
 * `$verbs` / `$nouns` are deprecated for scoring — kept for BC and folded into
 * the embedded text. Prefer `$summary`, `$examples`, and `$category`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class McpToolIntent
{
    /**
     * @param list<string> $verbs      Extra search words for find_tools
     * @param list<string> $nouns      Extra search words for find_tools
     * @param list<string> $modules    Backend modules where the tool is offered from the start, e.g. web_layout, records, file
     * @param list<string> $examples   Example requests, at least English and German, for find_tools
     * @param string       $summary    One-line English summary, shown to the model when find_tools returns the tool
     * @param string       $category   content|pages|seo|media_files|translation|…|general (module tool set)
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
