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

use NITSAN\NsT3AF\Agent\Embedding\EmbeddingSourceResolver;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Provider\Capability;

/**
 * Whether a provider save should invalidate / rebuild the agent tool embedding index.
 *
 * @internal
 */
final class ProviderAgentToolIndexRebuildPolicy
{
    public function __construct(
        private readonly AgentSettingsService $agentSettings,
    ) {}

    /**
     * @param array<string, mixed> $body Raw POST body (api_key presence).
     */
    public function shouldRebuildAfterSave(?Provider $before, Provider $after, array $body): bool
    {
        if ($this->agentSettings->getEmbeddingSource() === EmbeddingSourceResolver::MODE_NONE) {
            return false;
        }

        $hadEmbeddings = $before?->hasCapability(Capability::EMBEDDINGS) ?? false;
        $hasEmbeddings = $after->hasCapability(Capability::EMBEDDINGS);

        if (!$hasEmbeddings && !$hadEmbeddings) {
            return false;
        }

        if (!$hasEmbeddings && $hadEmbeddings) {
            return true;
        }

        if ($before === null) {
            return true;
        }

        if ($before->adapterType !== $after->adapterType) {
            return true;
        }

        if ($before->effectiveEmbeddingModel() !== $after->effectiveEmbeddingModel()) {
            return true;
        }

        if (trim($before->endpointUrl) !== trim($after->endpointUrl)) {
            return true;
        }

        if ($before->effectiveApiVersion() !== $after->effectiveApiVersion()) {
            return true;
        }

        $apiKey = is_scalar($body['api_key'] ?? null) ? trim((string) $body['api_key']) : '';

        return $apiKey !== '';
    }
}
