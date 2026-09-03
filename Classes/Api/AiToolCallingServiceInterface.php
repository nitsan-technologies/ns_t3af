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

namespace NITSAN\NsT3AF\Api;

use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;

/**
 * Sibling to {@see AiServiceInterface} for provider turns that may invoke tools.
 *
 * @api
 */
interface AiToolCallingServiceInterface
{
    /**
     * Whether the resolved provider's adapter supports function calling.
     */
    public function supportsToolCalling(?string $providerIdentifier = null, ?int $pageId = null): bool;

    /**
     * Run a completion that may return tool calls from the model.
     *
     * @param list<AiToolDefinition> $tools
     * @param list<array<string, mixed>> $messages Chat turns in provider shape
     *
     * @throws UnknownAdapterException
     * @throws AdapterRuntimeException When the adapter cannot call tools (fail loudly).
     */
    public function completeWithTools(
        array $messages,
        array $tools,
        AiOptions $options = new AiOptions(),
    ): AiToolCallingResponse;
}
