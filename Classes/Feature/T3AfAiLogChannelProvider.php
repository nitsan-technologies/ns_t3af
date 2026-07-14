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

namespace NITSAN\NsT3AF\Feature;

use NITSAN\NsT3AF\Contract\AiLogChannelProviderInterface;
use NITSAN\NsT3AF\Service\AiLogChannelCatalog;

final class T3AfAiLogChannelProvider implements AiLogChannelProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function getExtensionKey(): string
    {
        return 'ns_t3af';
    }

    public function getLogChannels(): array
    {
        return [
            AiLogChannelCatalog::CHANNEL_MCP,
            AiLogChannelCatalog::CHANNEL_MCP_CONFIG,
            AiLogChannelCatalog::CHANNEL_PROVIDERS,
            AiLogChannelCatalog::CHANNEL_WIZARD,
            AiLogChannelCatalog::CHANNEL_PROMPTS,
            AiLogChannelCatalog::CHANNEL_SCHEDULER_CLI,
        ];
    }

    public function includesLegacyExtensionChannel(): bool
    {
        return true;
    }

    public function inferWriteChannel(string $channel, array $extraData = []): ?string
    {
        return null;
    }
}
