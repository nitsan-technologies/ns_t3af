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

namespace NITSAN\NsT3AF\Mcp\Service;

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Utility\AiUniverseUtilityHelper;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Per tool-call MCP overrides for workspace and AI provider selection.
 */
final class McpInvocationContext
{
    public function __construct(private readonly WorkspaceListService $workspaceListService) {}

    private bool $active = false;

    private ?string $providerIdentifier = null;

    /**
     * The workspace the backend user was in before a per-call override, or null when none is active.
     * A long-lived process (the local stdio server) keeps ONE backend user for every call, so the override
     * has to be taken back after the call, or the next call without a workspaceId would still run in it.
     */
    private ?int $workspaceBeforeOverride = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function applyFromArguments(array $arguments): void
    {
        $this->active = true;
        $this->providerIdentifier = null;

        if (array_key_exists('workspaceId', $arguments)) {
            $workspaceId = (int) $arguments['workspaceId'];
            if ($workspaceId < 0) {
                throw new \RuntimeException('workspaceId must be zero or a positive sys_workspace uid.');
            }
            // 0 is schema default for Live; omit means use BE module preference (already applied).
            // Only positive UIDs override the global MCP Server workspace dropdown.
            if ($workspaceId > 0) {
                $this->applyWorkspace($workspaceId);
            }
        }

        if (!isset($arguments['aiProvider']) || !is_string($arguments['aiProvider'])) {
            return;
        }

        $providerIdentifier = trim($arguments['aiProvider']);
        if ($providerIdentifier === '' || $providerIdentifier === CreditsProviderIdentifier::IDENTIFIER) {
            return;
        }

        $this->providerIdentifier = $providerIdentifier;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function clear(): void
    {
        $this->active = false;
        $this->providerIdentifier = null;
        $this->restoreWorkspace();
    }

    public function getProviderIdentifier(): ?string
    {
        return $this->providerIdentifier;
    }

    public function enrichAiOptions(AiOptions $options): AiOptions
    {
        $providerIdentifier = $options->providerIdentifier;
        if ($this->providerIdentifier !== null) {
            $providerIdentifier = $this->providerIdentifier;
        }

        $requestSource = $this->active ? 'mcp' : $options->requestSource;

        if (
            $providerIdentifier === $options->providerIdentifier
            && $requestSource === $options->requestSource
        ) {
            return $options;
        }

        return new AiOptions(
            providerIdentifier: $providerIdentifier,
            modelId: $options->modelId,
            temperature: $options->temperature,
            systemPrompt: $options->systemPrompt,
            maxTokens: $options->maxTokens,
            noCache: $options->noCache,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            featureLabel: $options->featureLabel,
            requestSource: $requestSource,
            contentEntityType: $options->contentEntityType,
            contentEntityUid: $options->contentEntityUid,
            pageId: $options->pageId,
            requestUuid: $options->requestUuid,
            extra: $options->extra,
        );
    }

    /**
     * Credits mode skips provider override; still attribute the call as MCP when a tool is active.
     */
    public function enrichRequestSourceOnly(AiOptions $options): AiOptions
    {
        if (!$this->active || $options->requestSource === 'mcp') {
            return $options;
        }

        return new AiOptions(
            providerIdentifier: $options->providerIdentifier,
            modelId: $options->modelId,
            temperature: $options->temperature,
            systemPrompt: $options->systemPrompt,
            maxTokens: $options->maxTokens,
            noCache: $options->noCache,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            featureLabel: $options->featureLabel,
            requestSource: 'mcp',
            contentEntityType: $options->contentEntityType,
            contentEntityUid: $options->contentEntityUid,
            pageId: $options->pageId,
            requestUuid: $options->requestUuid,
            extra: $options->extra,
        );
    }

    public function assertProviderIsUsable(ProviderLookupInterface $providerLookup): void
    {
        if ($this->providerIdentifier === null) {
            return;
        }

        if ($this->providerIdentifier === CreditsProviderIdentifier::IDENTIFIER) {
            return;
        }

        $provider = $providerLookup->findByIdentifier($this->providerIdentifier);
        if ($provider === null) {
            throw new \RuntimeException(sprintf('AI provider "%s" was not found.', $this->providerIdentifier));
        }

        if ($provider->lastStatus !== McpConnectedProviderEnumResolver::STATUS_CONNECTED) {
            throw new \RuntimeException(sprintf(
                'AI provider "%s" is not connected (status: %s).',
                $this->providerIdentifier,
                $provider->lastStatus,
            ));
        }
    }

    private function applyWorkspace(int $workspaceId): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user context for MCP workspace override.');
        }

        if ($workspaceId < 0) {
            throw new \RuntimeException('workspaceId must be zero or a positive sys_workspace uid.');
        }

        if ($workspaceId > 0 && !AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            throw new \RuntimeException('typo3/cms-workspaces is not loaded; workspace overrides are unavailable.');
        }

        if ($workspaceId > 0) {
            $known = false;
            foreach ($this->workspaceListService->list() as $workspace) {
                if ((int) $workspace['uid'] === $workspaceId) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                throw new ToolCallException(sprintf('Workspace %d does not exist. Nothing was written.', $workspaceId), 1790500040);
            }
        }

        if (AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            // Per-call override: setWorkspace() would write the choice to the user's record and keep
            // the editor in that workspace across the whole backend after the call.
            $before = (int) $backendUser->workspace;
            if (!$backendUser->setTemporaryWorkspace($workspaceId)) {
                throw new ToolCallException(
                    sprintf('You are not a member of workspace %d, or it is not open to you. Nothing was written.', $workspaceId),
                    1790500041,
                );
            }

            // Only the first override of a call remembers where the user came from.
            $this->workspaceBeforeOverride ??= $before;
        }
    }

    /** Puts the backend user back into the workspace it was in before this call's override. */
    private function restoreWorkspace(): void
    {
        if ($this->workspaceBeforeOverride === null) {
            return;
        }

        $workspaceId = $this->workspaceBeforeOverride;
        $this->workspaceBeforeOverride = null;

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication && (int) $backendUser->workspace !== $workspaceId) {
            $backendUser->setTemporaryWorkspace($workspaceId);
        }
    }
}
