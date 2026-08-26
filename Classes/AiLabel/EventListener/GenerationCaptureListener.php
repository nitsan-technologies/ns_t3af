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

namespace NITSAN\NsT3AF\AiLabel\EventListener;

use NITSAN\NsT3AF\AiLabel\Service\GenerationCorrelationRegistry;
use NITSAN\NsT3AF\AiLabel\Service\OriginRecorder;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Event\AfterProviderResponseEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Record each provider response in the capture queue; children only bind on save.
 */
final class GenerationCaptureListener
{
    public function __invoke(AfterProviderResponseEvent $event): void
    {
        $options = $event->options;
        $provider = $event->provider;
        $response = $event->getResponse();

        $correlationId = bin2hex(random_bytes(16));
        $extension = $options->extensionKey ?? 'ns_t3af';
        $groupId = $options->featureKey ?? '';

        GeneralUtility::makeInstance(OriginRecorder::class)->capture(
            $correlationId,
            $extension,
            $provider->adapterType,
            $response->modelId,
            $groupId,
        );
        GenerationCorrelationRegistry::set($correlationId);

        $raw = $response->raw;
        $raw['ailabelCorrelationId'] = $correlationId;
        $event->setResponse(new AiResponse(
            content: $response->content,
            modelId: $response->modelId,
            providerIdentifier: $response->providerIdentifier,
            tokensInput: $response->tokensInput,
            tokensOutput: $response->tokensOutput,
            latencyMs: $response->latencyMs,
            cached: $response->cached,
            raw: $raw,
            credits: $response->credits,
            quality: $response->quality,
            appliedBrandContextProfileUid: $response->appliedBrandContextProfileUid,
        ));
    }
}
