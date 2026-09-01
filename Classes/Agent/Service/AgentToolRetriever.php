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

use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;

/**
 * Keyword + intent scoring to shortlist MCP tools before LLM tool-calling.
 *
 * @internal
 */
final class AgentToolRetriever
{
    public const DEFAULT_SHORTLIST = 12;

    public const WIDEN_SHORTLIST = 25;

    private const AUTO_INVOKE_MIN_SCORE = 14.0;

    private const AUTO_INVOKE_MIN_GAP = 4.0;

    /** @var list<string> */
    private const WRITE_VERBS = [
        'create', 'write', 'add', 'generate', 'draft', 'update', 'apply', 'translate', 'optimize', 'optimise',
        'make', 'build', 'compose',
    ];

    /** @var list<string> */
    private const TOPIC_VERBS = [
        'create', 'write', 'add', 'draft', 'generate', 'make', 'build', 'compose',
    ];

    /** @var list<string> */
    private const CONTENT_NOUNS = [
        'news', 'blog', 'article', 'post', 'page', 'content', 'element', 'block', 'story', 'outline', 'structure',
    ];

    /** @var list<string> */
    private const VARIANT_MODIFIERS = [
        'simple', 'advanced', 'basic', 'full', 'new',
    ];

    /** @var list<string> */
    private const TOOL_VARIANTS = [
        'simple', 'advanced', 'structure', 'element', 'batch',
    ];
    private const STOP_WORDS = [
        'the', 'this', 'that', 'these', 'those', 'please', 'could', 'would', 'should',
        'want', 'need', 'help', 'with', 'from', 'into', 'about', 'for', 'and', 'or',
        'but', 'not', 'are', 'was', 'were', 'have', 'has', 'had', 'can', 'will', 'just',
        'also', 'then', 'when', 'what', 'where', 'which', 'who', 'how', 'why', 'me', 'my',
        'your', 'our', 'all', 'any', 'some', 'new', 'using', 'use',
    ];

    /** @var list<string> */
    private const ACTION_VERBS = [
        'create', 'write', 'add', 'generate', 'translate', 'localize', 'localise',
        'update', 'edit', 'change', 'delete', 'remove', 'list', 'get', 'show', 'find',
        'search', 'inspect', 'read', 'open', 'copy', 'move', 'upload', 'publish',
        'optimize', 'optimise', 'improve', 'fix', 'draft', 'apply', 'schedule',
    ];

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $executableTools
     * @return list<array<string, mixed>>
     */
    public function shortlist(string $userMessage, array $context, array $executableTools, int $limit = self::DEFAULT_SHORTLIST): array
    {
        if ($executableTools === []) {
            return [];
        }

        $limit = max(1, min($limit, count($executableTools)));
        $shortlist = [];

        foreach (array_slice($this->rankTools($userMessage, $context, $executableTools), 0, $limit) as $row) {
            $shortlist[] = $row['tool'];
        }

        return $shortlist;
    }

    /**
     * Deterministic write-tool routing when retrieval confidence is high (or LLM returned empty).
     *
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $executableTools
     * @return array{tool: string, arguments: array<string, mixed>}|null
     */
    public function buildAutoInvocation(
        string $userMessage,
        array $context,
        array $executableTools,
        bool $afterLlmFailure = false,
    ): ?array {
        $ranked = $this->rankTools($userMessage, $context, $executableTools);
        if ($ranked === []) {
            return null;
        }

        $top = $ranked[0];
        $topTool = $top['tool'];
        $topScore = $top['score'];

        $severity = ToolSeverity::tryFromString((string) ($topTool['severity'] ?? ''));
        if ($severity !== ToolSeverity::Write) {
            return null;
        }

        if (!$this->messageLooksLikeWriteIntent($userMessage)) {
            return null;
        }

        $intent = is_array($topTool['intent'] ?? null) ? $topTool['intent'] : [];
        $pageId = (int) ($context['pageId'] ?? 0);
        if (($intent['requiresPage'] ?? false) === true && $pageId <= 0) {
            return null;
        }

        $minScore = $afterLlmFailure ? 4.0 : self::AUTO_INVOKE_MIN_SCORE;
        if ($topScore < $minScore) {
            return null;
        }

        if ($afterLlmFailure) {
            return $this->buildInvocationPayload($topTool, $userMessage, $context);
        }

        if ($this->toolRequiresSubject($topTool) && $this->extractSubject($userMessage) === '') {
            return null;
        }

        if ($this->topToolAlignsWithQuery($userMessage, $topTool)) {
            return $this->buildInvocationPayload($topTool, $userMessage, $context);
        }

        $competitorScore = $this->scoreGapToDistinctFamily($ranked);
        if (($topScore - $competitorScore) < self::AUTO_INVOKE_MIN_GAP) {
            return null;
        }

        return $this->buildInvocationPayload($topTool, $userMessage, $context);
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     * @return array{tool: string, arguments: array<string, mixed>}
     */
    private function buildInvocationPayload(array $tool, string $userMessage, array $context): array
    {
        $toolName = (string) ($tool['name'] ?? '');

        return [
            'tool' => $toolName,
            'arguments' => $this->buildToolArguments($tool, $userMessage, $context),
        ];
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildToolArguments(array $tool, string $userMessage, array $context): array
    {
        $arguments = [];
        $hints = $this->contextHints($tool);
        $subject = $this->extractSubject($userMessage);
        $subjectParam = is_string($hints['subjectParam'] ?? null) ? $hints['subjectParam'] : null;
        if ($subject !== '' && $subjectParam !== null) {
            $arguments[$subjectParam] = $subject;
        }

        $pageId = (int) ($context['pageId'] ?? 0);
        if ($pageId <= 0) {
            return $arguments;
        }

        $parentPageParam = is_string($hints['parentPageParam'] ?? null) ? $hints['parentPageParam'] : null;
        if ($parentPageParam !== null) {
            $arguments[$parentPageParam] = $pageId;
            return $arguments;
        }

        $newsStorageParam = is_string($hints['newsStorageParam'] ?? null) ? $hints['newsStorageParam'] : null;
        if ($newsStorageParam !== null) {
            $arguments[$newsStorageParam] = $pageId;
            return $arguments;
        }

        $pageParam = is_string($hints['pageParam'] ?? null) ? $hints['pageParam'] : null;
        if ($pageParam !== null) {
            $arguments[$pageParam] = $pageId;
            $arguments['pid'] ??= $pageId;
        } else {
            $arguments = $this->applyLegacyPageFallback(strtolower((string) ($tool['name'] ?? '')), $pageId, $arguments);
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function applyLegacyPageFallback(string $toolName, int $pageId, array $arguments): array
    {
        if (str_contains($toolName, 'create_page') || str_contains($toolName, '_page_structure') || str_contains($toolName, 'create_blog')) {
            $arguments['parentPageId'] = $pageId;
        } else {
            $arguments['pageId'] = $pageId;
            $arguments['pid'] = $pageId;
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function contextHints(array $tool): array
    {
        return is_array($tool['contextHints'] ?? null) ? $tool['contextHints'] : [];
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function toolRequiresSubject(array $tool): bool
    {
        $subjectParam = $this->contextHints($tool)['subjectParam'] ?? null;

        return is_string($subjectParam) && $subjectParam !== '';
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function topToolAlignsWithQuery(string $userMessage, array $tool): bool
    {
        $queryTokens = $this->tokenize($userMessage);
        if ($queryTokens === []) {
            return false;
        }

        $name = strtolower((string) ($tool['name'] ?? ''));
        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
        $nouns = $this->normalizeTerms(is_array($intent['nouns'] ?? null) ? array_values($intent['nouns']) : []);
        if ($nouns === []) {
            $nouns = $this->inferIntentFromName($name)['nouns'];
        }

        foreach ($queryTokens as $token) {
            if (in_array($token, $nouns, true)) {
                return true;
            }
            if (strlen($token) >= 4 && str_contains($name, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $executableTools
     * @return list<array{tool: array<string, mixed>, score: float}>
     */
    public function rankTools(string $userMessage, array $context, array $executableTools): array
    {
        if ($executableTools === []) {
            return [];
        }

        $queryTokens = $this->tokenize($userMessage);
        $queryVerbs = $this->extractVerbs($userMessage);
        $moduleFamily = $this->resolveModuleFamily((string) ($context['module'] ?? ''));

        $scored = [];
        foreach ($executableTools as $tool) {
            $scored[] = [
                'tool' => $tool,
                'score' => $this->scoreTool($tool, $queryTokens, $queryVerbs, $moduleFamily),
            ];
        }

        usort($scored, function (array $a, array $b) use ($queryTokens): int {
            $scoreCmp = $b['score'] <=> $a['score'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $variantCmp = $this->variantPreferenceRank((string) ($a['tool']['name'] ?? ''), $queryTokens)
                <=> $this->variantPreferenceRank((string) ($b['tool']['name'] ?? ''), $queryTokens);
            if ($variantCmp !== 0) {
                return $variantCmp;
            }

            return strcmp((string) ($a['tool']['name'] ?? ''), (string) ($b['tool']['name'] ?? ''));
        });

        $positive = array_values(array_filter(
            $scored,
            static fn(array $row): bool => $row['score'] > 0,
        ));

        return $positive !== [] ? $positive : $scored;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $executableTools
     * @return list<string>
     */
    public function topToolNames(string $userMessage, array $context, array $executableTools, int $limit = 5): array
    {
        $shortlist = $this->shortlist($userMessage, $context, $executableTools, $limit);

        return array_values(array_map(
            static fn(array $tool): string => (string) ($tool['name'] ?? ''),
            $shortlist,
        ));
    }

    private function messageLooksLikeWriteIntent(string $message): bool
    {
        $verbs = $this->extractVerbs($message);

        return array_intersect($verbs, self::WRITE_VERBS) !== [];
    }

    private function extractSubject(string $message): string
    {
        return $this->extractTopic($message);
    }

    private function extractTopic(string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return '';
        }

        $nouns = implode('|', self::CONTENT_NOUNS);
        $variants = implode('|', self::VARIANT_MODIFIERS);
        $verbs = implode('|', self::TOPIC_VERBS);

        $patterns = [
            '/\b(?:' . $verbs . ')\s+(?:a|an|the|new\s+)?(?:(?:' . $variants . ')\s+)*(?:[\p{L}\p{N}_-]+\s+){0,4}?(?:for|about|on|regarding)\s+(?:the\s+)?(.+)/iu',
            '/\b(?:' . $verbs . ')\s+(?:a|an|the|new\s+)?(?:(?:' . $variants . ')\s+)*(?:' . $nouns . ')(?:\s+(?:' . implode('|', self::TOOL_VARIANTS) . '|element|article|post))?\s*[:]\s*(.+)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $trimmed, $matches) === 1) {
                return $this->normalizeTopic($matches[1]);
            }
        }

        if (preg_match('/\b(?:' . $verbs . ')\s+(?:a|an|the|new\s+)?/iu', $trimmed) === 1) {
            $stripped = (string) preg_replace(
                '/^\s*(?:please\s+)?(?:' . $verbs . ')\s+(?:a|an|the|new\s+)?(?:(?:' . $variants . ')\s+)*(?:[\p{L}\p{N}_-]+\s+){0,4}?(?:for|about|on|regarding)?\s*/iu',
                '',
                $trimmed,
            );

            return $this->normalizeTopic($stripped);
        }

        return '';
    }

    /**
     * @param list<string> $queryTokens
     */
    private function variantPreferenceRank(string $toolName, array $queryTokens): int
    {
        $requestedVariant = null;
        foreach (self::TOOL_VARIANTS as $variant) {
            if (in_array($variant, $queryTokens, true)) {
                $requestedVariant = $variant;
                break;
            }
        }

        if ($requestedVariant !== null) {
            return str_contains(strtolower($toolName), '_' . $requestedVariant) ? 0 : 1;
        }

        return str_contains(strtolower($toolName), '_simple') ? 0 : 1;
    }

    private function normalizeTopic(string $topic): string
    {
        $topic = trim($topic);
        $topic = trim($topic, " \t\n\r\0\x0B\"'.,!?");

        return $topic;
    }

    /**
     * @param array<string, mixed> $tool
     * @param list<string> $queryTokens
     * @param list<string> $queryVerbs
     */
    private function scoreTool(array $tool, array $queryTokens, array $queryVerbs, string $moduleFamily): float
    {
        $name = strtolower((string) ($tool['name'] ?? ''));
        $description = strtolower((string) ($tool['description'] ?? ''));
        $nameTokens = $this->nameTokens($name);

        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
        $intentVerbs = $this->normalizeTerms(is_array($intent['verbs'] ?? null) ? array_values($intent['verbs']) : []);
        $intentNouns = $this->normalizeTerms(is_array($intent['nouns'] ?? null) ? array_values($intent['nouns']) : []);
        $intentModules = $this->normalizeTerms(is_array($intent['modules'] ?? null) ? array_values($intent['modules']) : []);

        if ($intentVerbs === [] && $intentNouns === []) {
            $inferred = $this->inferIntentFromName($name);
            $intentVerbs = $inferred['verbs'];
            $intentNouns = $inferred['nouns'];
        }

        $score = 0.0;

        foreach ($queryTokens as $token) {
            if (in_array($token, $nameTokens, true)) {
                $score += 4.0;
            }
            if (str_contains($name, $token)) {
                $score += 2.0;
            }
            if ($description !== '' && str_contains($description, $token)) {
                $score += 1.5;
            }
            if (in_array($token, $intentNouns, true)) {
                $score += 5.0;
            }
        }

        foreach ($queryVerbs as $verb) {
            if (in_array($verb, $intentVerbs, true)) {
                $score += 6.0;
            }
            if (in_array($verb, $nameTokens, true)) {
                $score += 3.0;
            }
        }

        if ($moduleFamily !== '' && $intentModules !== []) {
            foreach ($intentModules as $module) {
                if ($module === $moduleFamily || str_contains($moduleFamily, $module)) {
                    $score += 3.0;
                    break;
                }
            }
        }

        if (($intent['requiresPage'] ?? false) === true && $moduleFamily !== 'file') {
            $score += 0.5;
        }

        return $score;
    }

    /**
     * @return array{verbs: list<string>, nouns: list<string>}
     */
    private function inferIntentFromName(string $name): array
    {
        $tokens = $this->nameTokens($name);
        $verbs = [];
        $nouns = [];
        foreach ($tokens as $token) {
            if (in_array($token, self::ACTION_VERBS, true)) {
                $verbs[] = $token;
                continue;
            }
            if (strlen($token) >= 3 && !str_starts_with($token, 't3')) {
                $nouns[] = $token;
            }
        }

        return [
            'verbs' => array_values(array_unique($verbs)),
            'nouns' => array_values(array_unique($nouns)),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $message): array
    {
        $lower = strtolower(trim($message));
        if ($lower === '') {
            return [];
        }

        $tokens = [];
        if (preg_match_all('/\b[a-z][a-z0-9_]{2,}\b/', $lower, $matches) > 0) {
            foreach ($matches[0] as $word) {
                if (in_array($word, self::STOP_WORDS, true)) {
                    continue;
                }
                $tokens[] = $word;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string>
     */
    private function extractVerbs(string $message): array
    {
        $lower = strtolower($message);
        $verbs = [];
        foreach (self::ACTION_VERBS as $verb) {
            if (preg_match('/\b' . preg_quote($verb, '/') . '\b/', $lower) === 1) {
                $verbs[] = $verb;
            }
        }

        return $verbs;
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $parts = preg_split('/[^a-z0-9]+/', $name) ?: [];

        return array_values(array_filter(
            $parts,
            static fn(string $part): bool => $part !== '' && strlen($part) >= 2,
        ));
    }

    /**
     * @param array<mixed> $terms
     * @return list<string>
     */
    private function normalizeTerms(array $terms): array
    {
        $normalized = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                continue;
            }
            $value = strtolower(trim($term));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<array{tool: array<string, mixed>, score: float}> $ranked
     */
    private function scoreGapToDistinctFamily(array $ranked): float
    {
        if ($ranked === []) {
            return 0.0;
        }

        $topFamily = $this->toolFamilyKey((string) ($ranked[0]['tool']['name'] ?? ''));
        foreach (array_slice($ranked, 1) as $row) {
            $name = (string) ($row['tool']['name'] ?? '');
            if ($this->toolFamilyKey($name) !== $topFamily) {
                return (float) $row['score'];
            }
        }

        return 0.0;
    }

    private function toolFamilyKey(string $toolName): string
    {
        $normalized = strtolower(trim($toolName));
        $normalized = (string) preg_replace('/_(simple|advanced|batch|structure|element)$/', '', $normalized);

        return $normalized;
    }

    private function resolveModuleFamily(string $module): string
    {
        $normalized = strtolower(trim($module));
        if ($normalized === '') {
            return '';
        }

        if ($normalized === 'web_layout' || str_contains($normalized, 'layout')) {
            return 'web_layout';
        }

        if ($normalized === 'web_list' || $normalized === 'records' || str_contains($normalized, 'records')) {
            return 'records';
        }

        if (str_starts_with($normalized, 'file') || $normalized === 'media_management') {
            return 'file';
        }

        if (str_contains($normalized, 'redirect')) {
            return 'redirects';
        }

        if (str_contains($normalized, 'scheduler')) {
            return 'scheduler';
        }

        return $normalized;
    }
}
