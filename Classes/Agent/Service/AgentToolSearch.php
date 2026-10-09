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

use NITSAN\NsT3AF\Agent\Contract\AgentToolIndexInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;

/**
 * Ranks the editor's permitted tools for a free-text query (the agent's find_tools).
 *
 * Two rankings, merged with reciprocal rank fusion:
 * - provider embeddings (symfony/ai-store index), when an embedding provider is configured,
 * - BM25 over the tool text (name, editor label, description, intent and example prompts),
 *   always available and language-agnostic in the sense that it matches any words the
 *   examples contain (EN and DE examples are maintained).
 *
 * @internal
 */
final readonly class AgentToolSearch
{
    private const RRF_K = 60;

    private const BM25_K1 = 1.2;

    private const BM25_B = 0.75;

    /** Words that carry no meaning for tool search (EN + DE). */
    private const STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'to', 'for', 'in', 'on', 'at', 'by', 'with', 'from', 'is', 'are', 'be',
        'it', 'this', 'that', 'these', 'my', 'me', 'i', 'you', 'can', 'please', 'all', 'some', 'what', 'which', 'how',
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'einer', 'und', 'oder', 'zu', 'für',
        'fuer', 'in', 'im', 'auf', 'mit', 'von', 'vom', 'ist', 'sind', 'es', 'diese', 'dieser', 'dieses', 'diesen',
        'mein', 'meine', 'ich', 'du', 'sie', 'bitte', 'alle', 'was', 'wie', 'welche', 'kannst', 'mir',
    ];

    public function __construct(
        private AgentToolIndexInterface $toolIndex,
        private EmbeddingSourceResolver $embeddingSourceResolver,
        private AgentToolDocumentBuilder $documentBuilder,
        private AgentSettingsService $agentSettings,
    ) {}

    /**
     * @param list<array<string, mixed>> $candidates catalog entries the editor may run
     * @return array{tools: list<array<string, mixed>>, method: string}
     */
    public function search(string $query, array $candidates, int $limit = 5): array
    {
        $byName = [];
        foreach ($candidates as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name !== '') {
                $byName[$name] = $tool;
            }
        }
        if ($byName === [] || trim($query) === '') {
            return ['tools' => [], 'method' => 'none'];
        }

        $keywordRanking = $this->keywordRanking($query, $byName);
        $embeddingRanking = $this->embeddingRanking($query, $byName, $limit * 3);

        $fused = [];
        foreach ([$embeddingRanking, $keywordRanking] as $ranking) {
            foreach ($ranking as $rank => $name) {
                $fused[$name] = ($fused[$name] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
            }
        }
        arsort($fused);

        $tools = [];
        foreach (array_slice(array_keys($fused), 0, max(1, $limit)) as $name) {
            $tools[] = $byName[$name];
        }

        $method = match (true) {
            $embeddingRanking !== [] && $keywordRanking !== [] => 'embeddings+keywords',
            $embeddingRanking !== [] => 'embeddings',
            $keywordRanking !== [] => 'keywords',
            default => 'none',
        };

        return ['tools' => $tools, 'method' => $method];
    }

    /**
     * @param array<string, array<string, mixed>> $byName
     * @return list<string> tool names, best first
     */
    private function embeddingRanking(string $query, array $byName, int $limit): array
    {
        $source = $this->embeddingSourceResolver->resolve();
        if ($source === null) {
            return [];
        }

        try {
            $hits = $this->toolIndex->search($source->id(), $query, max($limit, 10));
        } catch (\Throwable) {
            return [];
        }

        $minSimilarity = $this->agentSettings->getMinSimilarity();
        $names = [];
        foreach ($hits as $hit) {
            $name = (string) $hit['name'];
            if (isset($byName[$name]) && (float) $hit['score'] >= $minSimilarity) {
                $names[] = $name;
            }
        }

        return array_slice($names, 0, $limit);
    }

    /**
     * BM25 with stem matching, so "Seiten" finds "Seite" and "translate" finds "translation".
     *
     * @param array<string, array<string, mixed>> $byName
     * @return list<string> tool names with a score > 0, best first
     */
    private function keywordRanking(string $query, array $byName): array
    {
        $queryTokens = array_values(array_unique($this->tokenize($query)));
        if ($queryTokens === []) {
            return [];
        }

        $documents = [];
        foreach ($byName as $name => $tool) {
            $documents[$name] = $this->tokenize($this->documentBuilder->build($tool, true));
        }
        $count = count($documents);
        $averageLength = max(1.0, array_sum(array_map('count', $documents)) / $count);

        $documentFrequency = [];
        foreach ($queryTokens as $token) {
            $documentFrequency[$token] = 0;
            foreach ($documents as $tokens) {
                if ($this->termFrequency($token, $tokens) > 0.0) {
                    ++$documentFrequency[$token];
                }
            }
        }

        $scores = [];
        foreach ($documents as $name => $tokens) {
            $score = 0.0;
            $length = count($tokens);
            foreach ($queryTokens as $token) {
                $tf = $this->termFrequency($token, $tokens);
                if ($tf <= 0.0) {
                    continue;
                }
                $idf = log(1 + ($count - $documentFrequency[$token] + 0.5) / ($documentFrequency[$token] + 0.5));
                $score += $idf * ($tf * (self::BM25_K1 + 1))
                    / ($tf + self::BM25_K1 * (1 - self::BM25_B + self::BM25_B * $length / $averageLength));
            }
            if ($score > 0.0) {
                $scores[$name] = $score;
            }
        }
        arsort($scores);

        return array_map('strval', array_keys($scores));
    }

    /**
     * Exact matches count 1. Words with a shared stem count 0.6: "Seite"/"Seiten", "translate"/"translation",
     * "übersetzen"/"Übersetzung" (common beginning of at least 4 characters and 70 % of the shorter word).
     *
     * @param list<string> $tokens
     */
    private function termFrequency(string $token, array $tokens): float
    {
        $tf = 0.0;
        foreach ($tokens as $candidate) {
            if ($candidate === $token) {
                $tf += 1.0;
            } elseif ($this->sharesStem($candidate, $token)) {
                $tf += 0.6;
            }
        }

        return $tf;
    }

    private function sharesStem(string $a, string $b): bool
    {
        $shorter = min(mb_strlen($a), mb_strlen($b));
        if ($shorter < 4) {
            return false;
        }
        $common = 0;
        while ($common < $shorter && mb_substr($a, $common, 1) === mb_substr($b, $common, 1)) {
            ++$common;
        }

        return $common >= 4 && $common >= 0.7 * $shorter;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 2 || in_array($part, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = $part;
        }

        return $tokens;
    }
}
