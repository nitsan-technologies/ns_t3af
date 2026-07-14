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

use NITSAN\NsT3AF\Credits\CreditsProviderIdentifier;
use NITSAN\NsT3AF\Credits\Service\CreditModeResolver;
use NITSAN\NsT3AF\Domain\Repository\ProviderRepositoryInterface;

/**
 * Lists AI providers for MCP tool schema enums.
 *
 * When T3Planet Credits routing is active, exposes a single synthetic option so MCP
 * clients see credits mode instead of local connected API-key providers.
 */
readonly class McpConnectedProviderEnumResolver
{
    public const STATUS_CONNECTED = 'connected';

    private const CREDITS_OPTION_TITLE = 'T3Planet Credits (active)';

    public function __construct(
        private ProviderRepositoryInterface $providerRepository,
        private CreditModeResolver $creditModeResolver,
    ) {}

    /**
     * @return list<array{identifier: string, title: string}>
     */
    public function resolveOptions(): array
    {
        if ($this->creditModeResolver->isActive()) {
            return [[
                'identifier' => CreditsProviderIdentifier::IDENTIFIER,
                'title' => self::CREDITS_OPTION_TITLE,
            ]];
        }

        $options = [];
        foreach ($this->providerRepository->findAll() as $provider) {
            if ($provider->lastStatus !== self::STATUS_CONNECTED) {
                continue;
            }
            if ($provider->identifier === '') {
                continue;
            }
            $options[] = [
                'identifier' => $provider->identifier,
                'title' => $provider->title !== '' ? $provider->title : $provider->identifier,
            ];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function resolveEnum(): array
    {
        return array_map(
            static fn(array $option): string => $option['identifier'],
            $this->resolveOptions(),
        );
    }

    public function buildDescription(): string
    {
        if ($this->creditModeResolver->isActive()) {
            return sprintf(
                'T3Planet Credits mode is active — AI requests route through T3Planet cloud billing, not your local API key providers. '
                . 'Select %s (%s) or omit this parameter.',
                CreditsProviderIdentifier::IDENTIFIER,
                self::CREDITS_OPTION_TITLE,
            );
        }

        $options = $this->resolveOptions();
        if ($options === []) {
            return 'AI Foundation provider identifier. Omit for the default provider. No connected providers are configured yet.';
        }

        $parts = array_map(
            static fn(array $option): string => sprintf('%s (%s)', $option['identifier'], $option['title']),
            $options,
        );

        return 'Connected AI Foundation provider identifier. Omit for the default provider. Options: ' . implode(', ', $parts) . '.';
    }
}
