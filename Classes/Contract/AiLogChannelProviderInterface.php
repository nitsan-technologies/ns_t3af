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

namespace NITSAN\NsT3AF\Contract;

/**
 * Declares sys_log channel identifiers for an extension (AI Logs tab).
 */
interface AiLogChannelProviderInterface
{
    public function isAvailable(): bool;

    public function getExtensionKey(): string;

    /**
     * @return list<string>
     */
    public function getLogChannels(): array;

    /**
     * When true, bare extension key values (e.g. ns_t3ai) are valid legacy channels.
     */
    public function includesLegacyExtensionChannel(): bool;

    /**
     * Infer a concrete channel when writes use empty or extension-key placeholders.
     * Return null when this provider does not handle the request.
     *
     * @param array<string, mixed> $extraData
     */
    public function inferWriteChannel(string $channel, array $extraData = []): ?string;
}
