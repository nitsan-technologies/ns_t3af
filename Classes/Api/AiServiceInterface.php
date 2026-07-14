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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Api;

use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;

/**
 * The single, semver-stable surface every child extension consumes.
 *
 * Inject this interface — never the concrete service, never an adapter, never
 * the registry. Three call shapes:
 *
 *  - {@see complete()}   — synchronous request/response
 *  - {@see stream()}     — Generator yielding string chunks as they arrive
 *  - {@see embed()}      — vector embedding(s) for retrieval pipelines
 *
 * Resolution rules (apply to every method that takes an {@see AiOptions}):
 *
 *  1. If `$options->providerIdentifier` is set, the matching record is used;
 *     missing identifier → {@see UnknownAdapterException}.
 *  2. Otherwise, the provider flagged `is_default` is used; none flagged →
 *     {@see UnknownAdapterException}.
 *  3. The adapter for that provider's `adapter_type` is fetched from the
 *     {@see \NITSAN\NsT3AF\Provider\AdapterRegistry}.
 *
 * @api
 */
interface AiServiceInterface
{
    /**
     * Run a non-streaming completion against the resolved provider.
     *
     * @throws UnknownAdapterException  When no provider matches the lookup.
     * @throws AdapterRuntimeException  When the adapter cannot reach the SDK
     *                                  or the SDK reports a runtime failure.
     */
    public function complete(string $prompt, AiOptions $options = new AiOptions()): AiResponse;

    /**
     * Stream completion chunks as they arrive from the provider.
     *
     * Yields one `string` per chunk (incremental delta, not cumulative). Never reads
     * from / writes to the response cache (streaming is mutually exclusive with cache).
     *
     * When T3Planet Credits mode is active, the generator return value is a
     * {@see StreamSummary} (usage settlement from the final SSE `usage` event).
     * With local adapters, the return value is `void`.
     *
     * @return \Generator<int, string, mixed, void|StreamSummary>
     *
     * @throws UnknownAdapterException
     * @throws AdapterRuntimeException
     */
    public function stream(string $prompt, AiOptions $options = new AiOptions()): \Generator;

    /**
     * Compute embedding vectors for one input or a batch.
     *
     * @param string|list<string> $text Single string or list of strings.
     *
     * @throws UnknownAdapterException
     * @throws AdapterRuntimeException
     */
    public function embed(string|array $text, AiOptions $options = new AiOptions()): EmbeddingResponse;

    /**
     * Resolve and return the {@see Provider} record that matches the lookup.
     *
     * Useful when the caller needs to inspect capabilities, model id, or
     * priority before issuing the actual request.
     *
     * @param string|null $identifier `null` resolves to the default provider.
     * @param int|null    $pageId     Page context used to resolve the storage
     *                                pid for multi-site provider lookups;
     *                                `null` falls back to the global default.
     *
     * @throws UnknownAdapterException When no matching record exists.
     */
    public function provider(?string $identifier = null, ?int $pageId = null): Provider;
}
