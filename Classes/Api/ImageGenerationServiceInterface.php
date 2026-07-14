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

use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;

/**
 * Semver-stable surface for AI image generation (OpenAI-compatible `/images/*` routes).
 *
 * Inject this interface — never the concrete {@see \NITSAN\NsT3AF\Service\ImageGenerationService}.
 *
 * The resolved provider MUST advertise {@see \NITSAN\NsT3AF\Provider\Capability::IMAGE_GENERATION}
 * or an {@see AdapterRuntimeException} is thrown.
 *
 * @api
 */
interface ImageGenerationServiceInterface
{
    /**
     * Generate images from a text prompt.
     *
     * @throws UnknownAdapterException  When no provider matches the lookup or the provider is disabled.
     * @throws AdapterRuntimeException  When the provider lacks image generation support or the API errors.
     */
    public function generate(string $prompt, ImageGenerationOptions $options = new ImageGenerationOptions()): ImageGenerationResponse;

    /**
     * Create a variation of an existing image file (OpenAI `/images/variations`).
     *
     * @param string $imagePath Absolute filesystem path to the source image.
     *
     * @throws UnknownAdapterException
     * @throws AdapterRuntimeException
     */
    public function variation(string $imagePath, ImageGenerationOptions $options = new ImageGenerationOptions()): ImageGenerationResponse;
}
