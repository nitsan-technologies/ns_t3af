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

/**
 * Resolves agentEmbeddingSource: auto|provider|transformers|none.
 *
 * @internal
 */
final class EmbeddingSourceResolver
{
    public const MODE_AUTO = 'auto';

    public const MODE_PROVIDER = 'provider';

    public const MODE_TRANSFORMERS = 'transformers';

    public const MODE_NONE = 'none';

    /**
     * @param iterable<EmbeddingSourceInterface> $sources
     */
    public function __construct(
        private readonly AgentSettingsService $agentSettings,
        private readonly iterable $sources,
    ) {}

    public function resolve(): ?EmbeddingSourceInterface
    {
        $mode = strtolower(trim($this->agentSettings->getEmbeddingSource()));
        if ($mode === '') {
            $mode = self::MODE_AUTO;
        }

        return match ($mode) {
            self::MODE_NONE => null,
            self::MODE_PROVIDER => $this->byId(ProviderEmbeddingSource::ID),
            self::MODE_TRANSFORMERS => $this->byId(TransformersEmbeddingSource::ID),
            default => $this->resolveAuto(),
        };
    }

    public function resolveById(string $id): ?EmbeddingSourceInterface
    {
        $source = $this->byId($id);
        if ($source === null || !$source->isAvailable()) {
            return null;
        }

        return $source;
    }

    public function modelIdFor(?EmbeddingSourceInterface $source): string
    {
        if ($source === null) {
            return 'none';
        }

        if ($source instanceof ProviderEmbeddingSource || $source instanceof TransformersEmbeddingSource) {
            return $source->modelId();
        }

        return $source->id();
    }

    private function resolveAuto(): ?EmbeddingSourceInterface
    {
        $provider = $this->byId(ProviderEmbeddingSource::ID);
        if ($provider !== null && $provider->isAvailable()) {
            return $provider;
        }

        $transformers = $this->byId(TransformersEmbeddingSource::ID);
        if ($transformers !== null && $transformers->isAvailable()) {
            return $transformers;
        }

        return null;
    }

    private function byId(string $id): ?EmbeddingSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->id() === $id) {
                return $source;
            }
        }

        return null;
    }
}
