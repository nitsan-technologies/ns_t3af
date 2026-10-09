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

namespace NITSAN\NsT3AF\Agent\PremiumCatalog;

use NITSAN\NsT3AF\Access\ExtensionAvailability;

/**
 * Last-resort capability lookup for {@see \NITSAN\NsT3AF\Agent\Runtime\T3afToolbox::findTools()}:
 * premium T3Planet extensions this site has not installed, so the Agent can say what is missing
 * instead of a generic "not possible" when `find_tools` draws a blank.
 *
 * Owned entirely by ns_t3af — no dependency on a child extension's PHP classes existing, unlike
 * {@see \NITSAN\NsT3AF\Contract\McpToolsExtensionCardProviderInterface} providers, which only
 * register once the child is actually installed. Taglines mirror the matching
 * `T3*McpToolsExtensionCardProvider` so copy stays consistent with the "MCP Tools" admin cards.
 *
 * @internal
 */
final readonly class PremiumCatalogProvider
{
    public function __construct(
        private ExtensionAvailability $extensionAvailability,
    ) {}

    /**
     * @return list<PremiumCatalogEntry>
     */
    public function all(): array
    {
        return [
            new PremiumCatalogEntry(
                extensionKey: 'ns_t3ai',
                label: 'AI Assistant',
                tagline: 'Content rewriting, SEO, translation, page generation, and RTE assistance.',
                searchTerms: [
                    'translate', 'translation', 'übersetzen', 'uebersetzen', 'sprache',
                    'seo', 'meta description', 'meta title', 'keywords', 'schema markup',
                    'rewrite', 'blog', 'news article', 'generate image', 'glossary',
                ],
                infoUrlEn: 'https://t3planet.de/en/t3ai-typo3-extension',
                infoUrlDe: 'https://t3planet.de/t3ai-typo3-erweiterung',
            ),
            new PremiumCatalogEntry(
                extensionKey: 'ns_t3aa',
                label: 'AI Accessibility',
                tagline: 'Alt text generation, file metadata, content summarization, translation and PageSpeed tools.',
                searchTerms: [
                    'alt text', 'alttext', 'accessibility', 'barrierefreiheit', 'wcag',
                    'voice over', 'voiceover', 'page speed', 'pagespeed', 'decorative image',
                ],
                infoUrlEn: 'https://t3planet.de/en/t3aa-typo3-extension',
                infoUrlDe: 'https://t3planet.de/t3aa-typo3-erweiterung',
            ),
            // ns_t3cs is the shared AI Suite backend for ns_t3as and ns_t3ac (crawling, datasource
            // sync, training) — not sold on its own, so it has no entry of its own here; buying
            // either product below installs it automatically.
            new PremiumCatalogEntry(
                extensionKey: 'ns_t3as',
                label: 'AI Search',
                tagline: 'Semantic site search, indexed content retrieval, and search history for TYPO3.',
                // No bare "search"/"suche": those collide with core pages_search / content_search
                // (e.g. "Search for page Home") and false-trigger the AgentRunner short-circuit.
                searchTerms: [
                    'semantic search', 'semantische suche', 'ai search', 'ki-suche', 'ki suche',
                    'search settings', 'predefined questions', 'search history', 'site search',
                    'seitensuche', 'crawl', 'crawler', 'datasource', 'data source',
                    'index content', 'knowledge base', 'wissensdatenbank',
                ],
                infoUrlEn: 'https://t3planet.de/en/t3as-typo3-extension',
                infoUrlDe: 'https://t3planet.de/t3as-typo3-erweiterung',
            ),
            new PremiumCatalogEntry(
                extensionKey: 'ns_t3ac',
                label: 'AI Chatbot',
                tagline: 'Embeddable AI chatbot, conversation flows, and frontend widget configuration.',
                searchTerms: [
                    'chatbot', 'chat bot', 'chat widget', 'conversation flow', 'embed chat',
                    'training queue', 'rag', 'knowledge base', 'wissensdatenbank', 'sync content',
                ],
                infoUrlEn: 'https://t3planet.de/en/t3ac-typo3-extension',
                infoUrlDe: 'https://t3planet.de/t3ac-typo3-erweiterung',
            ),
        ];
    }

    /**
     * The best-matching catalog entry for a free-text query, or null when nothing fits or the
     * matching extension is already loaded (the real tool/entitlement path owns that case).
     */
    public function findMatch(string $query): ?PremiumCatalogEntry
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($this->all() as $entry) {
            if ($this->extensionAvailability->isLoaded($entry->extensionKey)) {
                continue;
            }
            $score = 0;
            foreach ($entry->searchTerms as $term) {
                if (self::containsTerm($needle, $term)) {
                    ++$score;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return $best;
    }

    /**
     * Sequencing words/punctuation that mark a message as more than one request (EN + DE) — a
     * premium match here must not short-circuit a multi-step turn (e.g. "create this page, then
     * translate it"), so the model still gets to handle the whole request itself.
     *
     * @var list<string>
     */
    private const COMPOUND_SIGNALS = [
        '/\bthen\b/i', '/\bafter\s+that\b/i', '/\bafterwards?\b/i', '/\bnext,/i',
        '/\bdann\b/i', '/\bdanach\b/i', '/\banschlie(ß|ss)end\b/i',
    ];

    /**
     * {@see findMatch()}, but only for a message that reads like a single, standalone request —
     * used to short-circuit a whole turn before the model loop starts (see {@see \NITSAN\NsT3AF\Agent\Service\AgentRunner::runTurn()}).
     * Deliberately conservative: returns null on anything that looks compound, so a real
     * multi-step request always reaches the model normally.
     */
    public function findStandaloneMatch(string $query): ?PremiumCatalogEntry
    {
        return $this->looksCompound($query) ? null : $this->findMatch($query);
    }

    /**
     * Whole-word match, so "rag" does not fire inside "paragraphs" or "seo" inside another word.
     * Longer terms may carry an ending ("translate" finds "translated", "chatbot" finds
     * "chatbots"); short ones (up to 4 letters) only an optional plural "s".
     */
    private static function containsTerm(string $needle, string $term): bool
    {
        $ending = mb_strlen($term) > 4 ? '[\\p{L}]*' : 's?';

        return preg_match('/(?<![\\p{L}\\p{N}])' . preg_quote($term, '/') . $ending . '(?![\\p{L}\\p{N}])/u', $needle) === 1;
    }

    private function looksCompound(string $query): bool
    {
        foreach (self::COMPOUND_SIGNALS as $pattern) {
            if (preg_match($pattern, $query) === 1) {
                return true;
            }
        }

        // More than one sentence-ending mark, where the match is not just the trailing one.
        return preg_match('/[.!?](?!\s*$)/', trim($query)) === 1;
    }
}
