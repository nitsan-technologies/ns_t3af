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

namespace NITSAN\NsT3AF\DependencyInjection;

use NITSAN\NsT3AF\Provider\Contract\AdapterInterface;
use NITSAN\NsT3AF\Provider\SymfonyAi\BridgeDescriptor;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiBridgeAdapter;
use NITSAN\NsT3AF\Provider\SymfonyAi\SymfonyAiPlatformDiscovery;
use NITSAN\NsT3AF\Service\CredentialCipher;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * DI compiler pass that wires up every {@see AdapterInterface} implementation.
 *
 * Two responsibilities:
 *
 *  1. Register {@see AdapterInterface} for autoconfiguration so any service
 *     implementing it is automatically tagged with `nst3af.adapter`. The
 *     {@see \NITSAN\NsT3AF\Provider\AdapterRegistry} consumes that tag
 *     via `!tagged_iterator` in `Configuration/Services.yaml`.
 *
 *  2. For every Symfony AI Platform / SEAL bridge package found at compile time
 *     ({@see SymfonyAiPlatformDiscovery}), define one
 *     {@see SymfonyAiBridgeAdapter} service per descriptor — also tagged.
 *
 * (GPL-2.0-or-later).
 *
 * @internal
 */
final class AdapterCompilerPass implements CompilerPassInterface
{
    /**
     * Tag attached to every adapter service so the registry can collect them.
     */
    public const TAG = 'nst3af.adapter';

    public function __construct(
        private readonly ?SymfonyAiPlatformDiscovery $discovery = null,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(AdapterInterface::class)->addTag(self::TAG);

        $discovery = $this->discovery ?? new SymfonyAiPlatformDiscovery();
        foreach ($discovery->discover() as $descriptor) {
            $serviceId = 'nst3af.adapter.' . str_replace('.', '_', $descriptor->type);
            if ($container->hasDefinition($serviceId)) {
                continue;
            }

            // Symfony DI cannot serialize raw objects as constructor arguments,
            // so register the descriptor as its own definition with scalar args
            // and reference it from the adapter definition.
            $descriptorId = $serviceId . '.descriptor';
            $descriptorDefinition = new Definition(BridgeDescriptor::class);
            $descriptorDefinition->setArguments([
                $descriptor->packageName,
                $descriptor->vendorKey,
                $descriptor->type,
                $descriptor->displayName,
                $descriptor->defaultEndpoint,
                $descriptor->defaultCapabilities,
                // Authoritative phar-scoped factory/catalog FQNs (classic mode). Without these
                // the adapter falls back to deriving the FQN from the vendor key, which fails
                // for case-folded bridges (openai -> Openai vs the scoped OpenAi) under the
                // phar's case-sensitive classmap. Both are scalar strings/null — DI-serializable.
                $descriptor->factoryClass,
                $descriptor->catalogClass,
            ]);
            $descriptorDefinition->setPublic(false);
            $container->setDefinition($descriptorId, $descriptorDefinition);

            $definition = new Definition(SymfonyAiBridgeAdapter::class);
            $definition->setArguments([
                new Reference($descriptorId),
                new Reference(CredentialCipher::class),
                new Reference(RequestFactory::class),
            ]);
            $definition->setPublic(false);
            $definition->addTag(self::TAG);
            $container->setDefinition($serviceId, $definition);
        }
    }
}
