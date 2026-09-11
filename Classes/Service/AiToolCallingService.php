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

namespace NITSAN\NsT3AF\Service;

use NITSAN\NsT3AF\Api\AiOptions;
use NITSAN\NsT3AF\Api\AiToolCall;
use NITSAN\NsT3AF\Api\AiToolCallingResponse;
use NITSAN\NsT3AF\Api\AiToolCallingServiceInterface;
use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Domain\Model\Provider;
use NITSAN\NsT3AF\Domain\Repository\ProviderLookupInterface;
use NITSAN\NsT3AF\Event\BeforeProviderRequestEvent;
use NITSAN\NsT3AF\Exception\AdapterRuntimeException;
use NITSAN\NsT3AF\Exception\UnknownAdapterException;
use NITSAN\NsT3AF\Provider\AdapterRegistry;
use NITSAN\NsT3AF\Provider\Contract\ToolCallingCapableInterface;
use NITSAN\NsT3AF\Provider\OpenAiCompatible\OpenAiCompatiblePlatform;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiPlatform;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
final class AiToolCallingService implements AiToolCallingServiceInterface
{
    public function __construct(
        private readonly ProviderLookupInterface $providers,
        private readonly AdapterRegistry $adapters,
        private readonly EventDispatcherInterface $events,
        private readonly SiteStorageContext $siteStorageContext,
    ) {}

    public function supportsToolCalling(?string $providerIdentifier = null, ?int $pageId = null): bool
    {
        try {
            if ($providerIdentifier !== null && $providerIdentifier !== '') {
                $provider = $this->providers->findByIdentifier(
                    $providerIdentifier,
                    $this->siteStorageContext->resolveStoragePidFromPageId($pageId ?? 0),
                );
            } else {
                $provider = $this->providers->findDefault(
                    $this->siteStorageContext->resolveStoragePidFromPageId($pageId ?? 0),
                );
            }
            if ($provider === null) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $adapter = $this->adapters->get($provider->adapterType);

        return $adapter instanceof ToolCallingCapableInterface
            && $adapter->supportsToolCalling($provider);
    }

    public function completeWithTools(
        array $messages,
        array $tools,
        AiOptions $options = new AiOptions(),
    ): AiToolCallingResponse {
        $provider = $this->resolveProvider($options);
        $adapter = $this->adapters->get($provider->adapterType);

        if (!$adapter instanceof ToolCallingCapableInterface || !$adapter->supportsToolCalling($provider)) {
            throw new AdapterRuntimeException(
                sprintf(
                    'Provider "%s" (adapter "%s") cannot run tools. Choose a provider that supports function calling.',
                    $provider->identifier,
                    $provider->adapterType,
                ),
            );
        }

        $prompt = $this->messagesToPrompt($messages);
        $before = new BeforeProviderRequestEvent($provider, $prompt, $options, 'complete_with_tools');
        $this->events->dispatch($before);

        $modelId = $before->getOptions()->modelId ?? $provider->modelId;
        $platform = $adapter->platform($provider);
        $toolPayload = array_map(
            static fn(AiToolDefinition $definition): array => $definition->toProviderShape(),
            $tools,
        );

        $start = (int) (microtime(true) * 1000);
        if ($platform instanceof OpenAiCompatiblePlatform || $platform instanceof SymfonyAiPlatform) {
            $result = $platform->invokeWithTools($modelId, $messages, $toolPayload);
        } elseif (method_exists($platform, 'invokeWithTools')) {
            $result = $platform->invokeWithTools($modelId, $messages, $toolPayload);
        } else {
            throw new AdapterRuntimeException(
                sprintf('Adapter "%s" advertises tool calling but its platform cannot invoke tools.', $provider->adapterType),
            );
        }

        if (!is_array($result)) {
            throw new AdapterRuntimeException('Tool-calling platform returned an invalid result.');
        }

        $toolCalls = [];
        foreach (is_array($result['toolCalls'] ?? null) ? $result['toolCalls'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
            if ($name === '') {
                continue;
            }
            $toolCalls[] = new AiToolCall(
                id: isset($row['id']) && is_string($row['id']) ? $row['id'] : uniqid('call_', true),
                name: $name,
                arguments: is_array($row['arguments'] ?? null) ? $row['arguments'] : [],
            );
        }

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];

        return new AiToolCallingResponse(
            content: isset($result['content']) && is_string($result['content']) ? $result['content'] : '',
            modelId: $modelId,
            providerIdentifier: $provider->identifier,
            toolCalls: $toolCalls,
            tokensInput: (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            tokensOutput: (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
            latencyMs: (int) (microtime(true) * 1000) - $start,
            raw: is_array($result['raw'] ?? null) ? $result['raw'] : [],
            appliedBrandContextProfileUid: BrandContextLineage::profileUidFromOptions($before->getOptions()),
        );
    }

    private function resolveProvider(AiOptions $options): Provider
    {
        $storagePid = $this->siteStorageContext->resolveStoragePidFromPageId($options->pageId ?? 0);
        if ($options->providerIdentifier !== null && $options->providerIdentifier !== '') {
            $provider = $this->providers->findByIdentifier($options->providerIdentifier, $storagePid);
            if ($provider === null) {
                throw new UnknownAdapterException(sprintf('Provider "%s" not found.', $options->providerIdentifier));
            }

            return $provider;
        }

        $provider = $this->providers->findDefault($storagePid);
        if ($provider === null) {
            throw new UnknownAdapterException('No default AI provider configured.');
        }

        return $provider;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function messagesToPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = isset($message['role']) && is_string($message['role']) ? $message['role'] : 'user';
            $content = $message['content'] ?? '';
            if (is_string($content) && $content !== '') {
                $parts[] = $role . ': ' . $content;
            }
        }

        return implode("\n", $parts);
    }
}
