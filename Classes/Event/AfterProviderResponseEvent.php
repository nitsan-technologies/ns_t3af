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

namespace NITSAN\NsT3AF\Event;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Dispatched after a successful adapter call returns.
 *
 * Listeners may post-process the content (sanitization, attribution suffix,
 * link rewriting), record cost metrics, or update audit logs.
 *
 * @api
 */
final class AfterProviderResponseEvent
{
    private AiResponse $response;

    public function __construct(
        public readonly Provider $provider,
        AiResponse $response,
        public readonly AiOptions $options = new AiOptions(),
        public readonly string $prompt = '',
    ) {
        $this->response = $response;
    }

    public function getResponse(): AiResponse
    {
        return $this->response;
    }

    public function setResponse(AiResponse $response): void
    {
        $this->response = $response;
    }
}
