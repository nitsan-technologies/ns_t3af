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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Api\ImageGenerationOptions;
use NITSAN\NsT3AF\Api\ImageGenerationResponse;
use NITSAN\NsT3AF\Api\ImageGenerationServiceInterface;

/**
 * Routes image generation through T3Planet Credits when active; otherwise forwards to the inner service.
 *
 * @internal
 */
final class T3PlanetCreditImageService implements ImageGenerationServiceInterface
{
    public function __construct(
        private readonly ImageGenerationServiceInterface $inner,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly ProxyImageExecutor $proxyImageExecutor,
    ) {}

    public function generate(string $prompt, ImageGenerationOptions $options = new ImageGenerationOptions()): ImageGenerationResponse
    {
        if ($this->creditModeResolver->isActive()) {
            return $this->proxyImageExecutor->generate($prompt, $options);
        }

        return $this->inner->generate($prompt, $options);
    }

    public function variation(string $imagePath, ImageGenerationOptions $options = new ImageGenerationOptions()): ImageGenerationResponse
    {
        if ($this->creditModeResolver->isActive()) {
            return $this->proxyImageExecutor->variation($imagePath, $options);
        }

        return $this->inner->variation($imagePath, $options);
    }
}
