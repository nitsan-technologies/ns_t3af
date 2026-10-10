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

        if ($this->makesAnImageItself($query)) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        $bestInstalledScore = 0;
        foreach ($this->all() as $entry) {
            $score = 0;
            foreach ($entry->searchTerms as $term) {
                if (self::containsTerm($needle, $term)) {
                    ++$score;
                }
            }
            // Installed extensions are scored too, but never returned: they are not something to
            // sell. Their score decides whether this is an upsell at all — see below.
            if ($this->extensionAvailability->isLoaded($entry->extensionKey)) {
                $bestInstalledScore = max($bestInstalledScore, $score);
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        // An installed extension that matches the request at least as well owns it, so the real
        // tool/entitlement path decides. Otherwise "generate an image … with the alt text X" is
        // answered with an AI Accessibility upsell ("alt text" scores 1) even though the installed
        // ns_t3ai can generate the image ("generate image" scores 1 too) — a purchased feature
        // refused to sell another product.
        if ($bestInstalledScore >= $bestScore) {
            return null;
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
        if ($this->looksCompound($query) || self::placesAFile($query)) {
            return null;
        }

        return $this->findMatch($query);
    }

    /**
     * Attaching a file, naming a sys_file uid or creating a Text & Media element is core work
     * (file_reference_add, write_table); an alt text asked for with it goes on the file reference
     * and needs no AI Accessibility.
     *
     * @var list<string>
     */
    private const FILE_PLACEMENT_SIGNALS = [
        '/\battach\w*\b/i',
        '/\banh(?:ä|ae)ng\w*\b/iu',
        '/\bsys_file\b/i',
        '/\b(?:file|datei)[\s-]*uid\b/iu',
        '/\b(?:text\s*(?:&|and|und)\s*media|textmedia|textpic)\b/iu',
    ];

    /**
     * Asking for a made image while ns_t3ai is installed is T3AI work (`t3ai_generate_image`), and an
     * alt text named with it goes on the generated file — so it is not an AI Accessibility request.
     *
     * Deliberately broader than {@see \NITSAN\NsT3AF\Agent\Service\AgentRequestedFiles::asksForGeneratedImage()},
     * which only knows "generate …": editors also write "create a hero image of …". That matcher is
     * shared with the attach/checklist flow and must stay narrow, while the only cost of being
     * generous here is skipping an upsell.
     */
    private function makesAnImageItself(string $query): bool
    {
        if (!$this->extensionAvailability->isLoaded('ns_t3ai')) {
            return false;
        }

        return preg_match(
            '/\b(?:generat\w*|creat\w*|mak\w*|draw\w*|generier\w*|erzeug\w*|erstell\w*|zeichn\w*)'
            . '\s+(?:\w+\s+){0,3}'
            . '(?:images?|pictures?|photos?|illustrations?|graphics?|bild|bilder|foto|fotos|grafik\w*)\b/iu',
            $query,
        ) === 1;
    }

    private static function placesAFile(string $query): bool
    {
        foreach (self::FILE_PLACEMENT_SIGNALS as $pattern) {
            if (preg_match($pattern, $query) === 1) {
                return true;
            }
        }

        return false;
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
