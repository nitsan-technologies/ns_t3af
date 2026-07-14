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

namespace NITSAN\NsT3AF\Provider\SymfonyAi;

/**
 * Static metadata about one discovered Symfony AI Platform package.
 *
 * Built by {@see SymfonyAiPlatformDiscovery} and consumed by
 * {@see SymfonyAiBridgeAdapter} + the compiler pass that registers one adapter
 * service per descriptor.
 *
 * @internal
 */
final readonly class BridgeDescriptor
{
    /**
     * @param string       $packageName        Composer package, e.g. `symfony/ai-openai-platform`.
     * @param string       $vendorKey          Lower-case vendor slug, e.g. `openai`.
     * @param string       $type               Adapter type stored on Provider rows, e.g. `symfony.openai`.
     * @param string       $displayName        Backend module dropdown label.
     * @param string       $defaultEndpoint    URL pre-filled when this adapter is selected; `''` when none.
     * @param list<string> $defaultCapabilities Subset of {@see \NITSAN\NsT3AF\Provider\Capability::ALL}.
     * @param ?string      $factoryClass       Exact (possibly phar-scoped) Factory FQN when known from
     *                                          {@see \NITSAN\T3af\Runtime\PlatformRegistry}; null in Composer
     *                                          mode where the adapter derives it from the bridge namespace.
     * @param ?string      $catalogClass       Exact ModelCatalog FQN when known; null otherwise.
     */
    public function __construct(
        public string $packageName,
        public string $vendorKey,
        public string $type,
        public string $displayName,
        public string $defaultEndpoint,
        public array $defaultCapabilities,
        public ?string $factoryClass = null,
        public ?string $catalogClass = null,
    ) {}
}
