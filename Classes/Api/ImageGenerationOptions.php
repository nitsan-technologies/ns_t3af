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
 * Per-call options for image generation via {@see ImageGenerationServiceInterface}.
 *
 * @api
 */
final readonly class ImageGenerationOptions
{
    /**
     * @param string|null $providerIdentifier Override the default provider lookup.
     * @param string|null $modelId            Override the provider row's stored model (e.g. `dall-e-3`).
     * @param string      $size               OpenAI size token (e.g. `1024x1024`).
     * @param int         $count              Number of images to generate (OpenAI `n`).
     * @param string|null $extensionKey       Calling extension key for analytics attribution.
     * @param string|null $featureKey         Stable feature key for dashboard slicing.
     * @param string|null $requestSource      Source channel (backend_module|scheduler|cli|api).
     */
    public function __construct(
        public ?string $providerIdentifier = null,
        public ?string $modelId = null,
        public string $size = '1024x1024',
        public int $count = 1,
        public ?string $extensionKey = null,
        public ?string $featureKey = null,
        public ?string $requestSource = null,
    ) {}
}
