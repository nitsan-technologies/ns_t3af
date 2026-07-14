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
 * Semver-stable surface for text-to-speech synthesis.
 *
 * Inject this interface — never the concrete {@see \NITSAN\NsT3AF\Service\TtsService}.
 *
 * Resolution rules mirror {@see AiServiceInterface}: `$options->providerIdentifier`
 * selects a specific provider; `null` resolves to the default. The resolved provider
 * MUST advertise {@see \NITSAN\NsT3AF\Provider\Capability::TTS} or an
 * {@see AdapterRuntimeException} is thrown.
 *
 * Currently supported adapters: {@see \NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatibleAdapter}
 * (OpenAI `/audio/speech` endpoint). ElevenLabs is handled directly in consumer extensions.
 *
 * @api
 */
interface TtsServiceInterface
{
    /**
     * Synthesise speech from `$text` using the resolved provider.
     *
     * @param string     $text    Input text to convert to audio (max ~4 096 chars for OpenAI TTS).
     * @param TtsOptions $options Per-call overrides (voice, format, speed, provider, model).
     *
     * @throws UnknownAdapterException  When no provider matches the lookup or the provider is disabled.
     * @throws AdapterRuntimeException  When the provider lacks the TTS capability, the adapter has
     *                                  no speech() method, or the upstream API returns an error.
     */
    public function speak(string $text, TtsOptions $options = new TtsOptions()): TtsResponse;
}
