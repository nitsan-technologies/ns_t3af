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

/**
 * Per-call overrides for an AI request issued through {@see AiServiceInterface}.
 *
 * Every field is optional — `null` means "fall back to the provider record's
 * configured value". Pass via named arguments:
 *
 * ```php
 * $service->complete('Hello', new AiOptions(temperature: 0.0, noCache: true));
 * ```
 *
 * @api Stable surface — child extensions construct this directly.
 */
final readonly class AiOptions
{
    /**
     * @param string|null  $providerIdentifier Override the default provider lookup;
     *                                         `null` uses {@see \NITSAN\NsT3AF\Domain\Repository\ProviderRepository::findDefault()}.
     * @param string|null  $modelId            Override the provider row's stored model.
     * @param float|null   $temperature        Override `temperature`. `null` keeps the row default.
     * @param string|null  $systemPrompt       Replace the provider row's system prompt for this call only.
     * @param int|null     $maxTokens          Per-call token cap; `null` lets the provider decide.
     * @param bool         $noCache            Skip the response cache for this call (read AND write).
     * @param string|null  $extensionKey       Calling extension key (e.g. `ns_t3ai`) for analytics attribution.
     * @param string|null  $featureKey         Stable feature key (e.g. `seo.meta_description`) for dashboard slicing.
     * @param string|null  $featureLabel       Optional human-readable feature title used by UI summaries.
     * @param string|null  $requestSource      Source channel (`backend_module`, `scheduler`, `cli`, `api`, ...).
     * @param string|null  $contentEntityType  Optional domain object type (e.g. `pages`, `tt_content`).
     * @param int|null     $contentEntityUid   Optional domain object identifier.
     * @param string       $requestUuid        RFC 4122 idempotency key for T3Planet Credits Charge/Embed; empty string = auto UUID.
     * @param array<string, mixed> $extra      Adapter-specific extras (e.g. `top_p`, tools, `frequency_penalty`).
     *                                         Forwarded to the underlying SDK without validation.
     */
    public function __construct(
        public ?string $providerIdentifier = null,
        public ?string $modelId = null,
        public ?float $temperature = null,
        public ?string $systemPrompt = null,
        public ?int $maxTokens = null,
        public bool $noCache = false,
        public ?string $extensionKey = null,
        public ?string $featureKey = null,
        public ?string $featureLabel = null,
        public ?string $requestSource = null,
        public ?string $contentEntityType = null,
        public ?int $contentEntityUid = null,
        public ?int $pageId = null,
        public string $requestUuid = '',
        public array $extra = [],
    ) {}
}
