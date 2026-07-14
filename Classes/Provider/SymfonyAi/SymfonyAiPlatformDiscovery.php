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

use Composer\InstalledVersions;
use NITSAN\NsT3AF\Provider\Capability;

/**
 * Scans Composer's installed packages for `symfony/ai-*-platform` (and the
 * `lochmueller/seal-ai-*` re-exports) and produces one {@see BridgeDescriptor}
 * per match.
 *
 * Pure inspection — no SDK class is loaded here, so the result is safe to use
 * during the DI compile step ({@see \NITSAN\NsT3AF\DependencyInjection\AdapterCompilerPass}).
 *
 * (GPL-2.0-or-later).
 *
 * @internal
 */
final class SymfonyAiPlatformDiscovery
{
    /**
     * Symfony AI infrastructure packages installed transitively (e.g. by
     * symfony/ai-mistral-platform). Not user-facing providers — use Custom / Other instead.
     *
     * @var list<string>
     */
    private const EXCLUDED_PLATFORM_PACKAGES = [
        'symfony/ai-generic-platform',
    ];

    /**
     * @param (callable(): list<string>)|null $installedPackagesProvider Override for tests;
     *                                                                  defaults to {@see InstalledVersions}.
     */
    public function __construct(
        private readonly mixed $installedPackagesProvider = null,
    ) {}

    /**
     * @return list<BridgeDescriptor> One entry per matching installed package; never duplicates.
     */
    public function discover(): array
    {
        $descriptors = $this->discoverFromPhar();
        $seen = [];
        foreach ($descriptors as $d) {
            $seen[$d->type] = true;
        }

        $packages = $this->resolvePackages();
        foreach ($packages as $name) {
            if (!$this->isAiPlatformPackage($name) || $this->isExcludedPlatformPackage($name)) {
                continue;
            }
            $vendor = $this->extractVendor($name);
            if ($vendor === null) {
                continue;
            }
            $type = 'symfony.' . $this->canonicalVendor($vendor);
            if (isset($seen[$type])) {
                continue;
            }
            $descriptors[] = new BridgeDescriptor(
                packageName: $name,
                vendorKey: $vendor,
                type: $type,
                displayName: ucfirst($vendor) . ' (Symfony AI)',
                defaultEndpoint: $this->guessEndpoint($vendor),
                defaultCapabilities: $this->guessCapabilities($vendor),
            );
            $seen[$type] = true;
        }

        return $descriptors;
    }

    /**
     * Classic-mode TYPO3: read the un-scoped runtime registry shipped inside
     * the t3af.phar. Returns [] when the phar isn't loaded.
     *
     * @return list<BridgeDescriptor>
     */
    private function discoverFromPhar(): array
    {
        $registry = '\\NITSAN\T3af\\Runtime\\PlatformRegistry';
        if (!class_exists($registry)) {
            return [];
        }
        /** @var list<array{packageName:string,vendorKey:string,type:string,displayName:string,factoryClass:class-string,catalogClass:?class-string}> $bridges */
        $bridges = $registry::listBridges();
        $out = [];
        foreach ($bridges as $b) {
            $factoryClass = $this->resolveFactoryClassName($b['factoryClass']);
            if ($this->isExcludedPlatformPackage($b['packageName']) || !class_exists($factoryClass)) {
                continue;
            }
            $out[] = new BridgeDescriptor(
                packageName: $b['packageName'],
                vendorKey: $b['vendorKey'],
                type: $b['type'],
                displayName: $b['displayName'],
                defaultEndpoint: $this->guessEndpoint($b['vendorKey']),
                defaultCapabilities: $this->guessCapabilities($b['vendorKey']),
                factoryClass: $factoryClass,
                catalogClass: $b['catalogClass'],
            );
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function resolvePackages(): array
    {
        if ($this->installedPackagesProvider !== null) {
            /** @var callable(): list<string> $provider */
            $provider = $this->installedPackagesProvider;

            return $provider();
        }
        if (!class_exists(InstalledVersions::class)) {
            return [];
        }

        return array_values(InstalledVersions::getInstalledPackages());
    }

    private function isExcludedPlatformPackage(string $name): bool
    {
        return in_array($name, self::EXCLUDED_PLATFORM_PACKAGES, true);
    }

    private function isAiPlatformPackage(string $name): bool
    {
        if (preg_match('#^symfony/ai-[a-z0-9-]+-platform$#', $name) === 1) {
            return true;
        }

        return preg_match('#^lochmueller/seal-ai-[a-z0-9-]+$#', $name) === 1;
    }

    private function extractVendor(string $name): ?string
    {
        if (preg_match('#^symfony/ai-([a-z0-9-]+)-platform$#', $name, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#^lochmueller/seal-ai-([a-z0-9-]+)$#', $name, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function guessEndpoint(string $vendor): string
    {
        return match ($this->canonicalVendor($vendor)) {
            'openai' => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'ollama' => 'http://localhost:11434',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'bedrock' => '',
            'meta' => '',
            'generic' => '',
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    private function guessCapabilities(string $vendor): array
    {
        return match ($this->canonicalVendor($vendor)) {
            'openai', 'anthropic', 'gemini', 'mistral', 'bedrock', 'meta' => [
                Capability::CHAT,
                Capability::STREAMING,
                Capability::TOOL_USE,
            ],
            'ollama' => [Capability::CHAT, Capability::STREAMING, Capability::EMBEDDINGS],
            'huggingface' => [Capability::CHAT, Capability::EMBEDDINGS],
            'openrouter', 'generic' => [Capability::CHAT, Capability::STREAMING],
            default => [Capability::CHAT],
        };
    }

    /**
     * Normalise hyphenated package vendors (`open-ai` → `openai`) so the match
     * tables above treat them the same way Packagist's hyphenation does.
     */
    private function canonicalVendor(string $vendor): string
    {
        return str_replace('-', '', $vendor);
    }

    /**
     * Symfony AI 0.9 renamed bridge factories from PlatformFactory to Factory.
     * PlatformRegistry in older phar builds may still publish the legacy FQN.
     */
    private function resolveFactoryClassName(string $factoryClass): string
    {
        if (class_exists($factoryClass)) {
            return $factoryClass;
        }

        $renamed = str_replace('\\PlatformFactory', '\\Factory', $factoryClass);

        return class_exists($renamed) ? $renamed : $factoryClass;
    }
}
