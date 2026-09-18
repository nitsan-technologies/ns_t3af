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
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiServiceInterface;

/**
 * Semantic (embedding) shortlist of MCP tools for NL agent turns.
 *
 * @internal
 */
final class AgentToolShortlistService
{
    /** @var list<string> */
    private const ALWAYS_ON = [
        'ask_clarification',
        'explain_capabilities',
    ];

    /**
     * Backend module → preferred intent.category values for soft boost / fallback.
     *
     * @var array<string, list<string>>
     */
    private const MODULE_CATEGORY_HINTS = [
        'web_layout' => ['pages', 'content', 'seo'],
        'records' => ['records', 'content', 'seo'],
        'file' => ['media_files', 'files'],
        'media_management' => ['media_files', 'files'],
        'redirects' => ['redirects'],
        'scheduler' => ['scheduler'],
    ];

    private const CATEGORY_BOOST = 0.05;

    public function __construct(
        private readonly AgentToolIndexInterface $toolIndex,
        private readonly EmbeddingSourceResolver $embeddingSourceResolver,
        private readonly AgentSettingsService $agentSettings,
        private readonly AiServiceInterface $aiService,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @param list<array<string, mixed>> $executableTools
     * @param list<array<string, mixed>> $historyMessages
     * @return array{
     *     tools: list<array<string, mixed>>,
     *     routingSource: 'embeddings'|'category_pick'|'module_default'
     * }
     */
    public function shortlist(
        string $userMessage,
        array $context,
        array $executableTools,
        array $historyMessages = [],
        ?int $limitOverride = null,
    ): array {
        if ($executableTools === []) {
            return ['tools' => [], 'routingSource' => 'module_default'];
        }

        $limit = $limitOverride ?? $this->agentSettings->getShortlistSize();
        $limit = max(1, min($limit, count($executableTools)));
        $byName = $this->indexByName($executableTools);
        $alwaysOn = $this->filterAlwaysOn($byName);
        $continuity = $this->continuityTools($historyMessages, $byName);

        $source = $this->embeddingSourceResolver->resolve();
        if ($source !== null) {
            try {
                $this->toolIndex->ensureFresh();
                $hits = $this->toolIndex->search($source->id(), $userMessage, $limit);
                $minSimilarity = $this->agentSettings->getMinSimilarity();
                $moduleHints = $this->moduleCategoryHints((string) ($context['module'] ?? ''));
                $scored = [];
                foreach ($hits as $hit) {
                    $name = $hit['name'];
                    if (!isset($byName[$name])) {
                        continue;
                    }
                    $score = (float) $hit['score'];
                    if ($score < $minSimilarity) {
                        continue;
                    }
                    $category = $this->toolCategory($byName[$name]);
                    if ($category !== '' && in_array($category, $moduleHints, true)) {
                        $score += self::CATEGORY_BOOST;
                    }
                    $scored[$name] = $score;
                }

                if ($scored !== []) {
                    arsort($scored);
                    $picked = [];
                    foreach (array_keys($scored) as $name) {
                        $picked[$name] = $byName[$name];
                        if (count($picked) >= $limit) {
                            break;
                        }
                    }
                    $merged = $this->mergeNamed($picked, $alwaysOn, $continuity, $byName, $limit);

                    return [
                        'tools' => array_values($merged),
                        'routingSource' => 'embeddings',
                    ];
                }
            } catch (\Throwable) {
                // Fall through to category_pick / module_default.
            }
        }

        $categoryPick = $this->categoryPickFallback($userMessage, $byName, $alwaysOn, $continuity, $limit);
        if ($categoryPick !== null) {
            return $categoryPick;
        }

        return [
            'tools' => array_values($this->moduleDefaultFallback(
                $context,
                $byName,
                $alwaysOn,
                $continuity,
                $limit,
            )),
            'routingSource' => 'module_default',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $byName
     * @return array<string, array<string, mixed>>
     */
    private function filterAlwaysOn(array $byName): array
    {
        $out = [];
        foreach (self::ALWAYS_ON as $name) {
            if (isset($byName[$name])) {
                $out[$name] = $byName[$name];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $historyMessages
     * @param array<string, array<string, mixed>> $byName
     * @return array<string, array<string, mixed>>
     */
    private function continuityTools(array $historyMessages, array $byName): array
    {
        $recent = array_slice($historyMessages, -2);
        $out = [];
        foreach ($recent as $message) {
            if (!is_array($message)) {
                continue;
            }
            $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
            $tool = (string) ($meta['tool'] ?? '');
            if ($tool !== '' && isset($byName[$tool])) {
                $out[$tool] = $byName[$tool];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $picked
     * @param array<string, array<string, mixed>> $alwaysOn
     * @param array<string, array<string, mixed>> $continuity
     * @param array<string, array<string, mixed>> $byName
     * @return array<string, array<string, mixed>>
     */
    private function mergeNamed(
        array $picked,
        array $alwaysOn,
        array $continuity,
        array $byName,
        int $limit,
    ): array {
        $merged = $alwaysOn + $continuity + $picked;
        if (count($merged) <= $limit) {
            return $merged;
        }

        // Prefer always-on + continuity, then fill from picked order.
        $result = $alwaysOn + $continuity;
        foreach ($picked as $name => $tool) {
            if (isset($result[$name])) {
                continue;
            }
            $result[$name] = $tool;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $byName
     * @param array<string, array<string, mixed>> $alwaysOn
     * @param array<string, array<string, mixed>> $continuity
     * @return array{tools: list<array<string, mixed>>, routingSource: 'category_pick'}|null
     */
    private function categoryPickFallback(
        string $userMessage,
        array $byName,
        array $alwaysOn,
        array $continuity,
        int $limit,
    ): ?array {
        $categories = $this->askCategories($userMessage, $byName);
        if ($categories === []) {
            return null;
        }

        $picked = [];
        foreach ($byName as $name => $tool) {
            $category = $this->toolCategory($tool);
            if ($category !== '' && in_array($category, $categories, true)) {
                $picked[$name] = $tool;
            }
        }

        if ($picked === []) {
            return null;
        }

        $merged = $this->mergeNamed($picked, $alwaysOn, $continuity, $byName, $limit);

        return [
            'tools' => array_values(array_slice($merged, 0, $limit, true)),
            'routingSource' => 'category_pick',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, array<string, mixed>> $byName
     * @param array<string, array<string, mixed>> $alwaysOn
     * @param array<string, array<string, mixed>> $continuity
     * @return array<string, array<string, mixed>>
     */
    private function moduleDefaultFallback(
        array $context,
        array $byName,
        array $alwaysOn,
        array $continuity,
        int $limit,
    ): array {
        $hints = $this->moduleCategoryHints((string) ($context['module'] ?? ''));
        $picked = [];
        if ($hints !== []) {
            foreach ($byName as $name => $tool) {
                $category = $this->toolCategory($tool);
                if ($category !== '' && in_array($category, $hints, true)) {
                    $picked[$name] = $tool;
                }
            }
        }

        return $this->mergeNamed($picked, $alwaysOn, $continuity, $byName, $limit);
    }

    /**
     * @param array<string, array<string, mixed>> $byName
     * @return list<string>
     */
    private function askCategories(string $userMessage, array $byName): array
    {
        $available = [];
        foreach ($byName as $tool) {
            $category = $this->toolCategory($tool);
            if ($category !== '') {
                $available[$category] = true;
            }
        }
        $catalog = array_keys($available);
        if ($catalog === []) {
            return [];
        }

        $prompt = "Pick up to 3 tool categories for this editor request.\n"
            . 'Available categories: ' . implode(', ', $catalog) . "\n"
            . 'Request: ' . $userMessage . "\n"
            . 'Respond with JSON only: {"categories":["..."]}';

        try {
            $response = $this->aiService->complete(
                $prompt,
                new AiOptions(
                    extensionKey: 'ns_t3af',
                    featureKey: 'agent_routing',
                    featureLabel: 'AI Agent category pick',
                    requestSource: 'agent',
                    temperature: 0.0,
                    maxTokens: 200,
                ),
            );
            $content = trim($response->content);
            if ($content === '') {
                return [];
            }
            if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
                $content = $matches[0];
            }
            $decoded = json_decode($content, true);
            if (!is_array($decoded) || !is_array($decoded['categories'] ?? null)) {
                return [];
            }
            $picked = [];
            foreach ($decoded['categories'] as $category) {
                if (!is_string($category)) {
                    continue;
                }
                $normalized = strtolower(trim($category));
                if ($normalized !== '' && isset($available[$normalized])) {
                    $picked[] = $normalized;
                } elseif ($normalized !== '' && in_array($normalized, $catalog, true)) {
                    $picked[] = $normalized;
                } else {
                    foreach ($catalog as $known) {
                        if (strcasecmp($known, $normalized) === 0) {
                            $picked[] = $known;
                            break;
                        }
                    }
                }
                if (count($picked) >= 3) {
                    break;
                }
            }

            return array_values(array_unique($picked));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $executableTools
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $executableTools): array
    {
        $byName = [];
        foreach ($executableTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name !== '') {
                $byName[$name] = $tool;
            }
        }

        return $byName;
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function toolCategory(array $tool): string
    {
        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];

        return strtolower(trim((string) ($intent['category'] ?? '')));
    }

    /**
     * @return list<string>
     */
    private function moduleCategoryHints(string $module): array
    {
        $normalized = strtolower(trim($module));
        if ($normalized === '') {
            return [];
        }

        if (isset(self::MODULE_CATEGORY_HINTS[$normalized])) {
            return self::MODULE_CATEGORY_HINTS[$normalized];
        }

        if ($normalized === 'web_list' || str_contains($normalized, 'record')) {
            return self::MODULE_CATEGORY_HINTS['records'];
        }
        if (str_contains($normalized, 'layout')) {
            return self::MODULE_CATEGORY_HINTS['web_layout'];
        }
        if (str_starts_with($normalized, 'file') || $normalized === 'media_management') {
            return self::MODULE_CATEGORY_HINTS['file'];
        }
        if (str_contains($normalized, 'redirect')) {
            return self::MODULE_CATEGORY_HINTS['redirects'];
        }
        if (str_contains($normalized, 'scheduler')) {
            return self::MODULE_CATEGORY_HINTS['scheduler'];
        }

        return [];
    }
}
