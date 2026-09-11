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
 * Lightweight NL intent detection for workflow fast-paths (not per-tool routing).
 *
 * @internal
 */
final class AgentNlIntentResolver
{
    public const STEP_PAGE_INSPECT = 'page_inspect';

    public const STEP_TRANSLATE = 'translate';

    public const STEP_SEO_OPTIMIZE = 'seo_optimize';

    /**
     * Starter action id for multi-step workflows, e.g. generate_seo_metadata, or empty when none.
     */
    public function resolveStarterAction(string $message): string
    {
        if ($this->isCompoundTranslateSeoFlow($this->resolveCompoundSteps($message))) {
            return '';
        }

        $trimmed = trim($message);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match(
            '/\b(generate|create|write|update|optimize|optimise|improve|fix|draft|apply)\b.*\bseo\b/i',
            $trimmed,
        )) {
            return 'generate_seo_metadata';
        }

        if (preg_match(
            '/\bseo\b.*\b(generate|create|write|update|optimize|optimise|improve|fix|draft|metadata|meta data)\b/i',
            $trimmed,
        )) {
            return 'generate_seo_metadata';
        }

        if (preg_match('/\bgenerate\s+seo\s+metadata\b/i', $trimmed)) {
            return 'generate_seo_metadata';
        }

        return '';
    }

    /**
     * Ordered compound steps detected in the message (by first occurrence).
     *
     * @return list<string> Step ids: page_inspect, translate, seo_optimize
     */
    public function resolveCompoundSteps(string $message): array
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return [];
        }

        /** @var list<array{step: string, pos: int}> $candidates */
        $candidates = [];

        $inspectPos = $this->findPageInspectPosition($trimmed);
        if ($inspectPos !== null) {
            $candidates[] = ['step' => self::STEP_PAGE_INSPECT, 'pos' => $inspectPos];
        }

        if (preg_match('/\b(translate|translation|localize|localise)\b/i', $trimmed, $matches, PREG_OFFSET_CAPTURE)) {
            $candidates[] = ['step' => self::STEP_TRANSLATE, 'pos' => (int) $matches[0][1]];
        }

        $seoPos = $this->findSeoWriteIntentPosition($trimmed);
        if ($seoPos !== null) {
            $candidates[] = ['step' => self::STEP_SEO_OPTIMIZE, 'pos' => $seoPos];
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, static fn(array $a, array $b): int => $a['pos'] <=> $b['pos']);

        $steps = [];
        foreach ($candidates as $candidate) {
            if (!in_array($candidate['step'], $steps, true)) {
                $steps[] = $candidate['step'];
            }
        }

        return $steps;
    }

    /**
     * @param list<string> $steps
     */
    public function isCompoundTranslateSeoFlow(array $steps): bool
    {
        return in_array(self::STEP_TRANSLATE, $steps, true)
            && in_array(self::STEP_SEO_OPTIMIZE, $steps, true);
    }

    public function isSeoMetadataReadQuery(string $message): bool
    {
        if ($this->resolveStarterAction($message) !== '') {
            return false;
        }

        if (preg_match('/\b(translate|translation|localize|localise)\b/i', $message)) {
            return false;
        }

        if (!preg_match('/\b(seo|meta(?:\s|-)?description|search engine|og_|keywords)\b/i', $message)) {
            return false;
        }

        if (preg_match('/\b(generate|create|write|update|optimize|optimise|improve|fix|draft|apply|translate)\b/i', $message)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function extractContentSearchNeedles(string $message): array
    {
        if (!$this->looksLikePageContentQuery($message)) {
            return [];
        }

        return $this->extractSearchNeedles($message);
    }

    public function looksLikeFileAssetQuery(string $message): bool
    {
        return preg_match('/\b(image|images|file|files|fal|media|alt|caption|fileadmin|user_upload)\b/i', $message) === 1;
    }

    public function looksLikePageContentQuery(string $message): bool
    {
        if ($this->looksLikeFileAssetQuery($message)) {
            return false;
        }

        if (preg_match('/\b(seo|translate|translation|localize|localise|metadata|workspace|redirect|scheduler)\b/i', $message)) {
            return false;
        }

        if (preg_match('/\binvoice\b/i', $message)) {
            return true;
        }

        if (preg_match(
            '/\b(give|show|tell|find|get|what|where|list|read)\b.*\b(details|content|information|section|element|heading|block|text)\b/i',
            $message,
        ) === 1) {
            return true;
        }

        // Short title-like phrases (e.g. "Claude models") may name a tt_content header on this page.
        $trimmed = trim($message);
        if ($trimmed === '' || strlen($trimmed) > 80 || str_contains($trimmed, '/')) {
            return false;
        }

        return $this->extractSearchNeedles($trimmed) !== [];
    }

    /**
     * @return list<string>
     */
    private function extractSearchNeedles(string $message): array
    {
        /** @var list<string> $stopWords */
        $stopWords = [
            'give', 'show', 'tell', 'find', 'get', 'what', 'where', 'list', 'read', 'does', 'have',
            'the', 'this', 'that', 'page', 'current', 'me', 'please', 'could', 'would', 'from',
            'details', 'detail', 'information', 'info', 'content', 'data', 'about', 'anything',
            'something', 'need', 'want', 'are', 'there', 'any', 'all', 'some',
        ];

        $needles = [];
        $lower = strtolower($message);
        if (preg_match_all('/\b[a-z][a-z0-9_]{2,}\b/', $lower, $matches) > 0) {
            foreach ($matches[0] as $word) {
                if (in_array($word, $stopWords, true)) {
                    continue;
                }
                $needles[] = $word;
            }
        }

        $phrase = trim((string) preg_replace('/\s+/', ' ', preg_replace(
            '/\b(' . implode('|', array_map(static fn(string $word): string => preg_quote($word, '/'), $stopWords)) . ')\b/',
            ' ',
            $lower,
        ) ?? ''));
        if (strlen($phrase) >= 3) {
            $needles[] = $phrase;
        }

        return array_values(array_unique($needles));
    }

    private function findPageInspectPosition(string $message): ?int
    {
        if (preg_match(
            '/\b(get|fetch|read|show|open|inspect)\s+(?:the\s+)?(?:page\b|page\s+\S)/i',
            $message,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return (int) $matches[0][1];
        }

        return null;
    }

    private function findSeoWriteIntentPosition(string $message): ?int
    {
        if (preg_match(
            '/\b(generate|create|write|update|optimize|optimise|improve|fix|draft|apply)\b.*\bseo\b/i',
            $message,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return (int) $matches[0][1];
        }

        if (preg_match(
            '/\bseo\b.*\b(generate|create|write|update|optimize|optimise|improve|fix|draft|metadata|meta data)\b/i',
            $message,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return (int) $matches[0][1];
        }

        if (preg_match('/\bgenerate\s+seo\s+metadata\b/i', $message, $matches, PREG_OFFSET_CAPTURE)) {
            return (int) $matches[0][1];
        }

        return null;
    }
}
