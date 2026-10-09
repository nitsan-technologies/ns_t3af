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

use NITSAN\NsT3AF\Mcp\Service\Backend\McpToolMetadataService;

/**
 * The searchable text of one tool: name, description, #[McpToolIntent] summary and examples,
 * and the tagline, example prompts and search terms from Configuration/McpToolMetadata.yaml.
 *
 * Used by the embedding index (language-neutral, so the cached index does not change
 * with the editor's backend language) and by the keyword search (with the editor label
 * in the editor's language).
 *
 * @internal
 */
final readonly class AgentToolDocumentBuilder
{
    public function __construct(
        private AgentToolEditorLabelService $editorLabelService,
        private McpToolMetadataService $metadataService,
    ) {}

    /**
     * @param array<string, mixed> $tool introspected tool or catalog entry
     */
    public function build(array $tool, bool $withEditorLabel = false): string
    {
        $name = (string) ($tool['name'] ?? '');
        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
        $metadata = $name !== '' ? $this->metadataService->getForTool($name) : [];

        $parts = [
            str_replace('_', ' ', $name),
            $withEditorLabel ? $this->editorLabelService->resolve($tool) : '',
            (string) ($tool['description'] ?? ''),
            (string) ($intent['summary'] ?? ''),
            (string) ($metadata['tagline'] ?? ''),
            $this->join($intent['examples'] ?? []),
            $this->join($metadata['examplePrompts'] ?? []),
            $this->join($metadata['searchTerms'] ?? []),
            (string) ($intent['category'] ?? ''),
            trim($this->join($intent['verbs'] ?? []) . ' ' . $this->join($intent['nouns'] ?? [])),
        ];

        return trim(implode("\n", array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== '')));
    }

    /**
     * Category used to pick the module tools of the core set (lower case, e.g. "content", "files", "seo").
     *
     * @param array<string, mixed> $tool
     */
    public function category(array $tool): string
    {
        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
        $category = strtolower(trim((string) ($intent['category'] ?? '')));
        if ($category !== '') {
            return $category;
        }
        $name = (string) ($tool['name'] ?? '');
        if ($name === '' || !$this->metadataService->isDescribed($name)) {
            return '';
        }

        return strtolower(trim($this->metadataService->getForTool($name)['category']));
    }

    private function join(mixed $values): string
    {
        if (!is_array($values)) {
            return '';
        }

        return implode("\n", array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $values));
    }
}
