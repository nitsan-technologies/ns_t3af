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

    private ?string $lastModelId = null;

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
        $providerId = $this->agentSettings->getEmbeddingProvider();
        $options = new AiOptions(
            providerIdentifier: $providerId !== '' ? $providerId : null,
            extensionKey: 'ns_t3af',
            featureKey: 'agent_routing',
            featureLabel: 'AI Agent tool routing embeddings',
            requestSource: 'agent',
        );

        $response = $this->aiService->embed($text, $options);
        $this->lastModelId = $response->modelId !== '' ? $response->modelId : 'default';

        $vector = $response->vectors[0] ?? [];
        if ($vector === []) {
            throw new \RuntimeException('Provider embedding returned an empty vector.');
        }

        return array_values(array_map(static fn(mixed $v): float => (float) $v, $vector));
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
}
