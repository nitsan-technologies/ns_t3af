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
 * Per-call options for a text-to-speech request via {@see TtsServiceInterface}.
 *
 * Every field is optional — `null` means "fall back to the provider record's
 * configured value". Pass via named arguments:
 *
 * ```php
 * $tts->speak('Hello world', new TtsOptions(voice: 'nova', format: 'opus'));
 * ```
 *
 * @api Stable surface — child extensions construct this directly.
 */
final readonly class TtsOptions
{
    /**
     * @param string|null $providerIdentifier Override the default provider lookup;
     *                                        `null` uses {@see \NITSAN\NsT3AF\Domain\Repository\ProviderRepository::findDefault()}.
     * @param string|null $modelId            Override the provider row's stored model (e.g. `'tts-1'`, `'tts-1-hd'`).
     * @param string      $voice              OpenAI voice id: alloy|echo|fable|onyx|nova|shimmer.
     * @param string      $format             Audio format: mp3|opus|aac|flac|wav|pcm.
     * @param float       $speed              Playback speed multiplier (0.25–4.0).
     * @param string|null $extensionKey       Calling extension key for analytics attribution.
     * @param string|null $featureKey         Stable feature key for dashboard slicing.
     * @param string|null $requestSource      Source channel (backend_module|scheduler|cli|api).
     */
    public function __construct(
        public ?string $providerIdentifier = null,
        public ?string $modelId = null,
        public string $voice = 'alloy',
        public string $format = 'mp3',
        public float $speed = 1.0,
        public ?string $extensionKey = null,
        public ?string $featureKey = null,
        public ?string $requestSource = null,
    ) {}
}
