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

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiResponse;
use NITSAN\NsT3AF\Api\AiServiceInterface;
use NITSAN\NsT3AF\Api\EmbeddingResponse;
use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Routes AI calls through T3Planet Credits when active; otherwise forwards to inner service.
 *
 * @internal
 */
final class T3PlanetCreditAiService implements AiServiceInterface
{
    public function __construct(
        private readonly AiServiceInterface $inner,
        private readonly CreditModeResolver $creditModeResolver,
        private readonly ProxyAiExecutor $proxyAiExecutor,
    ) {}

    public function complete(string $prompt, AiOptions $options = new AiOptions()): AiResponse
    {
        if ($this->creditModeResolver->isActive()) {
            return $this->proxyAiExecutor->complete($prompt, $options);
        }

        return $this->inner->complete($prompt, $options);
    }

    public function stream(string $prompt, AiOptions $options = new AiOptions()): \Generator
    {
        if ($this->creditModeResolver->isActive()) {
            $stream = $this->proxyAiExecutor->stream($prompt, $options);
            yield from $stream;

            return $stream->getReturn();
        }

        // Propagate the delegated generator's return value (e.g. StreamSummary)
        // so getReturn() behaves the same with credits on or off (CM-01).
        $inner = $this->inner->stream($prompt, $options);
        yield from $inner;

        return $inner->getReturn();
    }

    public function embed(string|array $text, AiOptions $options = new AiOptions()): EmbeddingResponse
    {
        if ($this->creditModeResolver->isActive()) {
            return $this->proxyAiExecutor->embed($text, $options);
        }

        return $this->inner->embed($text, $options);
    }

    public function provider(?string $identifier = null, ?int $pageId = null): Provider
    {
        return $this->inner->provider($identifier, $pageId);
    }
}
