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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Contract\AiLogChannelProviderInterface;

/**
 * Canonical AI log channel identifiers stored in sys_log.channel.
 */
final class AiLogChannelCatalog
{
    public const FILTER_ALL = 'all';

    public const CHANNEL_SCHEDULER = 'scheduler';

    public const CHANNEL_MCP = 'nst3af.mcp';

    /** Max 20 chars — TYPO3 sys_log.channel column limit. */
    public const CHANNEL_MCP_CONFIG = 'nst3af.mcp_cfg';

    /** @deprecated Stored in older log rows only; use {@see CHANNEL_MCP_CONFIG}. */
    public const CHANNEL_MCP_CONFIG_LEGACY = 'nst3af.mcp_config';

    public const CHANNEL_PROVIDERS = 'nst3af.providers';

    public const CHANNEL_WIZARD = 'nst3af.wizard';

    public const CHANNEL_PROMPTS = 'nst3af.prompts';

    public const CHANNEL_SCHEDULER_CLI = 'nst3af.scheduler';

    /**
     * @param iterable<AiLogChannelProviderInterface> $channelProviders
     */
    public function __construct(
        private readonly iterable $channelProviders = [],
    ) {}

    /**
     * @return list<string>
     */
    public function getAllLogChannels(): array
    {
        $channels = [self::CHANNEL_SCHEDULER];
        foreach ($this->getLoadedExtensionKeys() as $extensionKey) {
            $channels = array_merge($channels, $this->logChannelsForExtension($extensionKey));
        }

        return array_values(array_unique($channels));
    }

    /**
     * @return list<string>
     */
    public function getLoadedExtensionKeys(): array
    {
        $extensions = [];
        foreach ($this->channelProviders as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }
            $extensions[] = $provider->getExtensionKey();
        }

        return array_values(array_unique($extensions));
    }

    /**
     * @return list<string> sys_log.channel values matching an extension filter.
     */
    public function resolveChannelValuesForExtension(string $extension): array
    {
        $extension = trim($extension);
        if ($extension === '' || $extension === self::FILTER_ALL) {
            return $this->getAllScopedChannelValues();
        }

        if (!in_array($extension, $this->getLoadedExtensionKeys(), true)) {
            return $this->getAllScopedChannelValues();
        }

        $values = [$extension];
        foreach ($this->logChannelsForExtension($extension) as $channel) {
            $values[] = $channel;
        }

        if ($extension === 'ns_t3af') {
            $values[] = self::CHANNEL_MCP_CONFIG_LEGACY;
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    public function getAllScopedChannelValues(): array
    {
        $values = $this->legacyExtensionChannels();
        foreach ($this->getAllLogChannels() as $channel) {
            $values[] = $channel;
        }

        return array_values(array_unique($values));
    }

    public function normalizeLogChannel(string $logChannel): string
    {
        $logChannel = trim($logChannel);
        if ($logChannel === '' || $logChannel === self::FILTER_ALL) {
            return self::FILTER_ALL;
        }

        if ($logChannel === self::CHANNEL_SCHEDULER) {
            return self::CHANNEL_SCHEDULER;
        }

        if ($logChannel === self::CHANNEL_MCP_CONFIG_LEGACY) {
            return self::CHANNEL_MCP_CONFIG;
        }

        return in_array($logChannel, $this->getAllLogChannels(), true) ? $logChannel : self::FILTER_ALL;
    }

    public function normalizeExtension(string $extension): string
    {
        $extension = trim($extension);
        if ($extension === '' || $extension === self::FILTER_ALL) {
            return self::FILTER_ALL;
        }

        return in_array($extension, $this->getLoadedExtensionKeys(), true) ? $extension : self::FILTER_ALL;
    }

    /**
     * @param array<string, mixed> $extraData
     */
    public function normalizeWriteChannel(string $channel, string $extensionKey = 'ns_t3af', array $extraData = []): string
    {
        $channel = trim($channel);
        $provider = $this->findProvider($extensionKey);
        if ($provider !== null) {
            $inferred = $provider->inferWriteChannel($channel, $extraData);
            if ($inferred !== null) {
                return $inferred;
            }
        }

        if ($channel !== '' && $this->isKnownChannel($channel)) {
            return $channel;
        }

        if (in_array($channel, $this->legacyExtensionChannels(), true)) {
            return $channel;
        }

        if ($channel !== '') {
            return $channel;
        }

        return $extensionKey !== '' ? $extensionKey : 'ns_t3af';
    }

    public function isKnownChannel(string $channel): bool
    {
        if ($channel === self::CHANNEL_SCHEDULER) {
            return true;
        }

        if (in_array($channel, $this->legacyExtensionChannels(), true)) {
            return true;
        }

        return in_array($channel, $this->getAllLogChannels(), true);
    }

    /**
     * @return list<array{value:string,labelKey:string}>
     */
    public function buildLogChannelOptions(): array
    {
        $options = [
            ['value' => self::FILTER_ALL, 'labelKey' => 'module.aiLogs.logChannel.all'],
        ];
        foreach ($this->getAllLogChannels() as $channel) {
            $options[] = [
                'value' => $channel,
                'labelKey' => 'module.aiLogs.logChannel.' . str_replace('.', '_', $channel),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value:string,labelKey:string}>
     */
    public function buildExtensionOptions(): array
    {
        $options = [
            ['value' => self::FILTER_ALL, 'labelKey' => 'module.aiLogs.extension.all'],
        ];
        foreach ($this->getLoadedExtensionKeys() as $extensionKey) {
            $options[] = [
                'value' => $extensionKey,
                'labelKey' => 'module.aiLogs.channel.' . $extensionKey,
            ];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function legacyExtensionChannels(): array
    {
        $channels = [];
        foreach ($this->channelProviders as $provider) {
            if ($provider->includesLegacyExtensionChannel()) {
                $channels[] = $provider->getExtensionKey();
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * @return list<string>
     */
    private function logChannelsForExtension(string $extensionKey): array
    {
        $provider = $this->findProvider($extensionKey);

        return $provider?->getLogChannels() ?? [];
    }

    private function findProvider(string $extensionKey): ?AiLogChannelProviderInterface
    {
        foreach ($this->channelProviders as $provider) {
            if ($provider->getExtensionKey() === $extensionKey) {
                return $provider;
            }
        }

        return null;
    }
}
