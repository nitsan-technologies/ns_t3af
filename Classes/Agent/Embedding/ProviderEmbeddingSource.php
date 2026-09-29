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

namespace NITSAN\NsT3AF\Agent\Embedding;

use NITSAN\NsT3AF\Agent\Contract\EmbeddingSourceInterface;
use NITSAN\NsT3AF\Agent\Service\AgentSettingsService;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiServiceInterface;

/**
 * Embeddings via the configured AI provider (AiServiceInterface::embed).
 *
 * @internal
 */
final class ProviderEmbeddingSource implements EmbeddingSourceInterface
{
    public const ID = 'provider';

    /** Upstream batch size — keeps cold index rebuilds under rate limits. */
    public const BATCH_SIZE = 16;

    private ?string $lastModelId = null;

    /** @var array<string, list<float>> In-request cache (query + duplicate docs). */
    private array $vectorCache = [];

    public function __construct(
        private readonly AiServiceInterface $aiService,
        private readonly AgentSettingsService $agentSettings,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function isAvailable(): bool
    {
        try {
            $providerId = $this->agentSettings->getEmbeddingProvider();
            $this->aiService->provider(
                $providerId !== '' ? $providerId : null,
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function embed(string $text): array
    {
        return $this->embedMany([$text])[0];
    }

    public function embedMany(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $out = [];
        $missIndexes = [];
        $missTexts = [];

        foreach ($texts as $i => $text) {
            $key = $this->cacheKey($text);
            if (isset($this->vectorCache[$key])) {
                $out[$i] = $this->vectorCache[$key];
                continue;
            }
            $missIndexes[] = $i;
            $missTexts[] = $text;
        }

        if ($missTexts !== []) {
            $fetched = $this->fetchBatches($missTexts);
            foreach ($missIndexes as $j => $i) {
                $vector = $fetched[$j];
                $this->vectorCache[$this->cacheKey($missTexts[$j])] = $vector;
                $out[$i] = $vector;
            }
        }

        ksort($out);

        return array_values($out);
    }

    public function modelId(): string
    {
        if ($this->lastModelId !== null && $this->lastModelId !== '') {
            return $this->lastModelId;
        }

        try {
            $providerId = $this->agentSettings->getEmbeddingProvider();
            $provider = $this->aiService->provider(
                $providerId !== '' ? $providerId : null,
            );
            $model = trim($provider->effectiveEmbeddingModel());

            return $model !== '' ? $model : 'default';
        } catch (\Throwable) {
            return 'default';
        }
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    private function fetchBatches(array $texts): array
    {
        $options = $this->options();
        $all = [];

        foreach (array_chunk($texts, self::BATCH_SIZE) as $chunk) {
            $response = $this->aiService->embed($chunk, $options);
            $this->lastModelId = $response->modelId !== '' ? $response->modelId : 'default';

            if (count($response->vectors) !== count($chunk)) {
                throw new \RuntimeException(sprintf(
                    'Provider embedding returned %d vectors for %d inputs.',
                    count($response->vectors),
                    count($chunk),
                ));
            }

            foreach ($response->vectors as $raw) {
                $vector = array_values(array_map(static fn(mixed $v): float => (float) $v, $raw));
                if ($vector === []) {
                    throw new \RuntimeException('Provider embedding returned an empty vector.');
                }
                $all[] = $vector;
            }
        }

        return $all;
    }

    private function options(): AiOptions
    {
        $providerId = $this->agentSettings->getEmbeddingProvider();

        return new AiOptions(
            providerIdentifier: $providerId !== '' ? $providerId : null,
            extensionKey: 'ns_t3af',
            featureKey: 'agent_routing',
            featureLabel: 'AI Agent tool routing embeddings',
            requestSource: 'agent',
        );
    }

    private function cacheKey(string $text): string
    {
        return hash('xxh128', $text);
    }
}
