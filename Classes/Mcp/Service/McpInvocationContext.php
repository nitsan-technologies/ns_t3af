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

namespace NITSAN\NsT3AF\Mcp\Service;

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

    private ?string $providerIdentifier = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function applyFromArguments(array $arguments): void
    {
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

    public function getProviderIdentifier(): ?string
    {
        return $this->providerIdentifier;
    }

    public function enrichAiOptions(AiOptions $options): AiOptions
    {
        if ($this->providerIdentifier === null) {
            return $options;
        }

        return new AiOptions(
            providerIdentifier: $this->providerIdentifier,
            modelId: $options->modelId,
            temperature: $options->temperature,
            systemPrompt: $options->systemPrompt,
            maxTokens: $options->maxTokens,
            noCache: $options->noCache,
            extensionKey: $options->extensionKey,
            featureKey: $options->featureKey,
            featureLabel: $options->featureLabel,
            requestSource: $options->requestSource,
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
                throw new \RuntimeException(sprintf('Workspace uid %d was not found.', $workspaceId));
            }
        }

        if (AiUniverseUtilityHelper::isExtensionLoaded('workspaces')) {
            $backendUser->setWorkspace($workspaceId);
        }
    }

}
