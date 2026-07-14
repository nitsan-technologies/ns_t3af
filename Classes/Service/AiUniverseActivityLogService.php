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

/**
 * Major admin/configuration events for the AI Foundation backend module.
 *
 * @internal
 */
final class AiUniverseActivityLogService
{
    public function __construct(
        private readonly AiLogService $logService,
    ) {}

    public function logProviderSaved(int $uid, string $identifier, string $title, bool $created): void
    {
        $action = $created ? 'created' : 'updated';
        $this->log(
            AiLogChannelCatalog::CHANNEL_PROVIDERS,
            sprintf('AI provider %s: "%s" (%s, uid %d).', $action, $title, $identifier, $uid),
        );
    }

    public function logProviderDeleted(int $uid, string $identifier, string $title): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_PROVIDERS,
            sprintf('AI provider deleted: "%s" (%s, uid %d).', $title, $identifier, $uid),
        );
    }

    public function logProviderSetDefault(string $identifier, string $title): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_PROVIDERS,
            sprintf('Default AI provider set to "%s" (%s).', $title, $identifier),
        );
    }

    public function logWizardFinalized(string $mode, bool $mcpEnabled): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_WIZARD,
            sprintf(
                'Quick Setup wizard completed (mode: %s, MCP server %s).',
                $mode,
                $mcpEnabled ? 'enabled' : 'disabled',
            ),
        );
    }

    public function logPromptSync(int $created): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_PROMPTS,
            $created > 0
                ? sprintf('AI prompts synchronized (%d new prompt(s)).', $created)
                : 'AI prompts synchronized (no new prompts).',
        );
    }

    public function logPromptCreated(string $category, string $title): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_PROMPTS,
            sprintf('AI prompt created in "%s": %s.', $category, $title),
        );
    }

    public function logPromptUpdated(string $category, int $uid, string $title): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_PROMPTS,
            sprintf('AI prompt updated in "%s" (uid %d): %s.', $category, $uid, $title),
        );
    }

    public function logPromptDeleted(string $category, int $uid): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_PROMPTS,
            sprintf('AI prompt deleted from "%s" (uid %d).', $category, $uid),
        );
    }

    public function logSchedulerCommandRun(string $command, bool $success): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_SCHEDULER_CLI,
            sprintf('Scheduler CLI command %s: %s.', $command, $success ? 'completed' : 'failed'),
            $success ? 'info' : 'error',
        );
    }

    public function logMcpSettingsSaved(string $area): void
    {
        $this->log(
            AiLogChannelCatalog::CHANNEL_MCP_CONFIG,
            sprintf('MCP server settings saved (%s).', $area),
        );
    }

    private function log(string $channel, string $message, string $level = 'info'): void
    {
        $this->logService->writeLog($message, $level, $channel);
    }
}
