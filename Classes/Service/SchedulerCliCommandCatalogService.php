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

use NITSAN\NsT3AF\Registry\McpToolsExtensionCardProviderRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use TYPO3\CMS\Core\Console\CommandRegistry;

final class SchedulerCliCommandCatalogService
{
    private const COMMAND_PREFIX = 't3af:';

    /**
     * Legacy scheduler/console aliases registered alongside canonical t3af:* names.
     *
     * @var list<string>
     */
    private const LEGACY_COMMAND_PREFIXES = [
        'nst3af:',
        'nst3ai:',
        'nst3aa:',
        'nst3cs:',
    ];

    /**
     * Retired command names kept as CLI aliases on canonical commands — hide from catalog listings.
     *
     * @var array<string, string>
     */
    private const DEPRECATED_COMMAND_ALIASES = [
        't3af:log:clear' => 't3af:ai-logs:cleanup',
        'nst3ai:log:clear' => 't3af:ai-logs:cleanup',
        'nst3ai:clean:ailog' => 't3af:ai-logs:cleanup',
        'ns_t3ai:clean:ailog' => 't3af:ai-logs:cleanup',
    ];

    /**
     * @var array<string, string>
     */
    private const NAMESPACE_EXTENSION_MAP = [
        'NITSAN\\NsT3AF\\' => 'ns_t3af',
        'NITSAN\\NsT3Ai\\' => 'ns_t3ai',
        'NITSAN\\NsT3AA\\' => 'ns_t3aa',
        'NITSAN\\NsT3Cs\\' => 'ns_t3cs',
    ];

    public function __construct(
        private readonly CommandRegistry $commandRegistry,
        private readonly McpToolsExtensionCardProviderRegistry $cardProviderRegistry,
    ) {}

    /**
     * @return list<array{
     *   id:string,
     *   name:string,
     *   command:string,
     *   extension:string,
     *   category:string,
     *   description:string,
     *   schedulable:int,
     *   arguments:list<array{name:string,label:string,required:int,placeholder:string}>,
     *   options:list<array{name:string,label:string,hasValue:int,placeholder:string}>
     * }>
     */
    public function all(): array
    {
        $entries = [];
        foreach ($this->commandRegistry->filter('t3af') as $commandName => $configuration) {
            if (!str_starts_with($commandName, self::COMMAND_PREFIX)) {
                continue;
            }
            if (isset(self::DEPRECATED_COMMAND_ALIASES[$commandName])) {
                continue;
            }

            try {
                $command = $this->commandRegistry->getCommandByIdentifier($commandName);
            } catch (\Throwable) {
                continue;
            }

            $serviceName = (string) ($configuration['serviceName'] ?? '');
            $feature = $this->resolveFeature($commandName);
            $description = trim((string) ($configuration['description'] ?? $command->getDescription()));
            if ($description === '') {
                $description = $commandName;
            }

            $entries[] = [
                'id' => str_replace(':', '-', $commandName),
                'name' => $this->humanizeCommandName($commandName, $description),
                'command' => $commandName,
                'extension' => $this->resolveExtensionKey($serviceName),
                'category' => $feature,
                'description' => $description,
                'schedulable' => ($configuration['schedulable'] ?? true) ? 1 : 0,
                'arguments' => $this->extractArguments($command),
                'options' => $this->extractOptions($command),
            ];
        }

        usort(
            $entries,
            static fn(array $a, array $b): int => strcmp((string) $a['command'], (string) $b['command']),
        );

        return $entries;
    }

    public function isSchedulerCliCommand(string $command): bool
    {
        $needle = trim($command);
        if ($needle === '') {
            return false;
        }

        if (str_starts_with($needle, self::COMMAND_PREFIX)) {
            return true;
        }

        foreach (self::LEGACY_COMMAND_PREFIXES as $prefix) {
            if (str_starts_with($needle, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCommand(string $command): ?array
    {
        $needle = trim($command);
        if ($needle === '') {
            return null;
        }

        foreach ($this->all() as $entry) {
            if ((string) ($entry['command'] ?? '') === $needle) {
                return $entry;
            }
        }

        $canonical = $this->resolveCanonicalCommand($needle);
        if ($canonical === $needle) {
            return null;
        }

        foreach ($this->all() as $entry) {
            if ((string) ($entry['command'] ?? '') === $canonical) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *   id:string,
     *   label:string,
     *   icon:string,
     *   iconBg:string,
     *   color:string,
     *   tagline:string,
     *   commandCount:int,
     *   commands:list<array<string, mixed>>
     * }>
     */
    public function extensionGroups(): array
    {
        $grouped = [];
        foreach ($this->all() as $command) {
            $extensionKey = (string) ($command['extension'] ?? 'unknown');
            $grouped[$extensionKey][] = $this->enrichCommandForDisplay($command);
        }

        $catalog = $this->loadExtensionCatalog();
        $groups = [];
        foreach ($grouped as $extensionKey => $commands) {
            $config = $catalog[$extensionKey] ?? $this->defaultExtensionConfig($extensionKey);
            $groups[] = [
                'id' => $extensionKey,
                'label' => (string) ($config['label'] ?? $extensionKey),
                'icon' => (string) ($config['icon'] ?? '🧩'),
                'iconBg' => (string) ($config['iconBg'] ?? '#f3f4f6'),
                'color' => (string) ($config['color'] ?? '#737373'),
                'tagline' => (string) ($config['tagline'] ?? ''),
                'commandCount' => count($commands),
                'commands' => $commands,
            ];
        }

        usort(
            $groups,
            static fn(array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']),
        );

        return $groups;
    }

    private function normalizeLegacyCommand(string $command): string
    {
        foreach (self::LEGACY_COMMAND_PREFIXES as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return self::COMMAND_PREFIX . substr($command, strlen($prefix));
            }
        }

        return $command;
    }

    private function resolveCanonicalCommand(string $command): string
    {
        $normalized = $this->normalizeLegacyCommand($command);

        return self::DEPRECATED_COMMAND_ALIASES[$normalized]
            ?? self::DEPRECATED_COMMAND_ALIASES[$command]
            ?? $normalized;
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function enrichCommandForDisplay(array $command): array
    {
        $commandName = (string) ($command['command'] ?? '');
        $description = trim((string) ($command['description'] ?? ''));

        return array_merge($command, [
            'shortTitle' => $description !== '' ? $description : $commandName,
            'cliSnippet' => 'vendor/bin/typo3 ' . $commandName,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function loadExtensionCatalog(): array
    {
        $configs = $this->cardProviderRegistry->buildExtensionConfigs();
        $configs['ns_t3af'] = array_replace(
            $configs['ns_t3af'] ?? [],
            [
                'extensionKey' => 'ns_t3af',
                'label' => 'AI Foundation',
                'icon' => '🌐',
                'iconBg' => '#e0e7ff',
                'color' => '#4338ca',
                'tagline' => 'MCP server, provider gateway, credits, and shared AI infrastructure.',
            ],
        );

        return $configs;
    }

    /** @return array<string, mixed> */
    private function defaultExtensionConfig(string $extensionKey): array
    {
        return [
            'extensionKey' => $extensionKey,
            'label' => ucfirst(str_replace('_', ' ', $extensionKey)),
            'icon' => '🧩',
            'iconBg' => '#f3f4f6',
            'color' => '#6b7280',
            'tagline' => 'AI Foundation CLI commands.',
        ];
    }

    /**
     * @return list<array{name:string,label:string,required:int,placeholder:string}>
     */
    private function extractArguments(Command $command): array
    {
        $rows = [];
        foreach ($command->getDefinition()->getArguments() as $argument) {
            if (!$argument instanceof InputArgument) {
                continue;
            }
            $rows[] = [
                'name' => $argument->getName(),
                'label' => $argument->getDescription() !== '' ? $argument->getDescription() : $argument->getName(),
                'required' => $argument->isRequired() ? 1 : 0,
                'placeholder' => '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name:string,label:string,hasValue:int,placeholder:string}>
     */
    private function extractOptions(Command $command): array
    {
        $rows = [];
        foreach ($command->getDefinition()->getOptions() as $option) {
            if (!$option instanceof InputOption) {
                continue;
            }
            if (in_array($option->getName(), ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'], true)) {
                continue;
            }
            $default = $option->getDefault();
            $rows[] = [
                'name' => $option->getName(),
                'label' => $option->getDescription() !== '' ? $option->getDescription() : $option->getName(),
                'hasValue' => $option->acceptValue() ? 1 : 0,
                'default' => $default !== null ? (string) $default : '',
                'placeholder' => $default !== null ? (string) $default : '',
            ];
        }

        return $rows;
    }

    private function resolveFeature(string $commandName): string
    {
        $parts = explode(':', $commandName);
        return $parts[1] ?? 'general';
    }

    private function resolveExtensionKey(string $serviceName): string
    {
        foreach (self::NAMESPACE_EXTENSION_MAP as $prefix => $extensionKey) {
            if (str_starts_with($serviceName, $prefix)) {
                return $extensionKey;
            }
        }

        return 'unknown';
    }

    private function humanizeCommandName(string $commandName, string $description): string
    {
        $parts = explode(':', $commandName);
        $action = $parts[2] ?? $parts[1] ?? $commandName;
        $feature = $parts[1] ?? '';
        $label = ucfirst(str_replace(['-', '_'], ' ', $action));
        if ($feature !== '') {
            return ucfirst(str_replace(['-', '_'], ' ', $feature)) . ' — ' . $label;
        }

        return $description !== $commandName ? $description : $label;
    }
}
