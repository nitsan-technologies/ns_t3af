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
use NITSAN\NsT3AF\Agent\Contract\EmbeddingSourceInterface;
use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Cache\CacheFacadeInterface;
use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\InMemory\Store;
use Symfony\AI\Store\Query\VectorQuery;

/**
 * Builds and queries the agent tool embedding index (TYPO3 cache + InMemory store).
 *
 * @internal
 */
final class AgentToolIndexService implements AgentToolIndexInterface
{
    public const CACHE_IDENTIFIER = 'nst3af_agent_tool_index';

    private const CACHE_KEY = 'tool_index_v1';

    private ?Store $hydratedStore = null;

    private ?string $hydratedFingerprint = null;

    public function __construct(
        private readonly CacheFacadeInterface $cache,
        private readonly EmbeddingSourceResolver $embeddingSourceResolver,
        private readonly McpToolIntrospectorService $toolIntrospector,
        private readonly PermittedActionProvider $permittedActionProvider,
        private readonly AgentToolEditorLabelService $editorLabelService,
    ) {}

    public function rebuild(): void
    {
        $source = $this->embeddingSourceResolver->resolve();
        if ($source === null) {
            $this->cache->remove(self::CACHE_KEY);
            $this->hydratedStore = null;
            $this->hydratedFingerprint = null;

            return;
        }

        $this->writeIndex($source);
    }

    public function ensureFresh(): void
    {
        $source = $this->embeddingSourceResolver->resolve();
        if ($source === null) {
            return;
        }

        $expectedHash = $this->definitionHash();
        $modelId = $this->embeddingSourceResolver->modelIdFor($source);
        $cached = $this->cache->get(self::CACHE_KEY);
        if (
            is_array($cached)
            && ($cached['sourceId'] ?? null) === $source->id()
            && ($cached['modelId'] ?? null) === $modelId
            && ($cached['hash'] ?? null) === $expectedHash
            && is_array($cached['documents'] ?? null)
        ) {
            return;
        }

        $this->writeIndex($source);
    }

    public function search(string $queryEmbeddingSource, string $message, int $limit): array
    {
        $source = $this->embeddingSourceResolver->resolveById($queryEmbeddingSource)
            ?? $this->embeddingSourceResolver->resolve();
        if ($source === null) {
            return [];
        }

        $this->ensureFresh();
        $store = $this->hydrateStore($source);
        if ($store === null) {
            return [];
        }

        $queryVector = $source->embed($message);
        $limit = max(1, $limit);
        $results = $store->query(
            new VectorQuery(new Vector($queryVector)),
            ['maxItems' => $limit],
        );

        $out = [];
        foreach ($results as $document) {
            if (!$document instanceof VectorDocument) {
                continue;
            }
            $name = (string) $document->getId();
            $distance = $document->getScore();
            // Cosine distance score → similarity in [≈0, 1]
            $similarity = $distance === null ? 0.0 : max(0.0, 1.0 - (float) $distance);
            $out[] = [
                'name' => $name,
                'score' => $similarity,
            ];
        }

        return $out;
    }

    private function writeIndex(EmbeddingSourceInterface $source): void
    {
        $modelId = $this->embeddingSourceResolver->modelIdFor($source);
        $hash = $this->definitionHash();
        $documents = [];

        foreach ($this->indexableTools() as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $text = $this->buildDocumentText($tool);
            $vector = $source->embed($text);
            $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
            $documents[] = [
                'id' => $name,
                'vector' => $vector,
                'metadata' => [
                    'name' => $name,
                    'category' => (string) ($intent['category'] ?? ''),
                    'severity' => (string) ($tool['severity'] ?? ''),
                    'ownerExtensionKey' => (string) ($tool['ownerExtensionKey'] ?? 'ns_t3af'),
                ],
                'text' => $text,
            ];
        }

        $payload = [
            'sourceId' => $source->id(),
            'modelId' => $modelId,
            'hash' => $hash,
            'documents' => $documents,
        ];
        $this->cache->set(self::CACHE_KEY, $payload, ['nst3af_agent_tool_index']);
        $this->hydratedStore = null;
        $this->hydratedFingerprint = null;
    }

    private function hydrateStore(EmbeddingSourceInterface $source): ?Store
    {
        $modelId = $this->embeddingSourceResolver->modelIdFor($source);
        $fingerprint = $source->id() . '|' . $modelId;
        if ($this->hydratedStore !== null && $this->hydratedFingerprint === $fingerprint) {
            return $this->hydratedStore;
        }

        $cached = $this->cache->get(self::CACHE_KEY);
        if (!is_array($cached) || !is_array($cached['documents'] ?? null)) {
            return null;
        }
        if (($cached['sourceId'] ?? null) !== $source->id() || ($cached['modelId'] ?? null) !== $modelId) {
            return null;
        }

        $store = new Store();
        foreach ($cached['documents'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $vector = $row['vector'] ?? null;
            if ($id === '' || !is_array($vector) || $vector === []) {
                continue;
            }
            $metaPayload = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $metadata = new Metadata($metaPayload);
            if (is_string($row['text'] ?? null) && $row['text'] !== '') {
                $metadata->setText((string) $row['text']);
            }
            $floatVector = array_values(array_map(static fn(mixed $v): float => (float) $v, $vector));
            $store->add(new VectorDocument($id, new Vector($floatVector), $metadata));
        }

        $this->hydratedStore = $store;
        $this->hydratedFingerprint = $fingerprint;

        return $store;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function indexableTools(): array
    {
        $tools = [];
        foreach ($this->toolIntrospector->listTools() as $tool) {
            if ($this->permittedActionProvider->isHiddenFromAgent($tool)) {
                continue;
            }
            $tools[] = $tool;
        }

        return $tools;
    }

    private function definitionHash(): string
    {
        $parts = [];
        foreach ($this->indexableTools() as $tool) {
            $parts[] = $this->buildDocumentText($tool);
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $tool
     */
    private function buildDocumentText(array $tool): string
    {
        $intent = is_array($tool['intent'] ?? null) ? $tool['intent'] : [];
        $title = $this->editorLabelService->resolve($tool);
        $description = (string) ($tool['description'] ?? '');
        $summary = (string) ($intent['summary'] ?? '');
        $examples = is_array($intent['examples'] ?? null)
            ? implode(' ', array_map(static fn(mixed $e): string => (string) $e, $intent['examples']))
            : '';
        $category = (string) ($intent['category'] ?? '');
        $verbs = is_array($intent['verbs'] ?? null)
            ? implode(' ', array_map(static fn(mixed $v): string => (string) $v, $intent['verbs']))
            : '';
        $nouns = is_array($intent['nouns'] ?? null)
            ? implode(' ', array_map(static fn(mixed $n): string => (string) $n, $intent['nouns']))
            : '';

        return trim(implode("\n", array_filter([
            $title,
            $description,
            $summary,
            $examples,
            $category,
            trim($verbs . ' ' . $nouns),
        ], static fn(string $part): bool => $part !== '')));
    }
}
