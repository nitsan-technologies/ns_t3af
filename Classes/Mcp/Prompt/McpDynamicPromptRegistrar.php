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

namespace NITSAN\NsT3AF\Mcp\Prompt;

use Mcp\Server\Builder;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateRepository;
use NITSAN\NsT3AF\Mcp\Service\Backend\McpPromptTemplateService;

/**
 * Registers DB-backed MCP prompt templates with the MCP server builder.
 */
readonly class McpDynamicPromptRegistrar
{
    public function __construct(
        private McpPromptTemplateService $promptTemplateService,
        private McpPromptTemplateRepository $promptTemplateRepository,
    ) {}

    public function register(Builder $builder): void
    {
        $this->promptTemplateService->ensureDefaults();

        foreach ($this->promptTemplateRepository->findVisible() as $template) {
            $name = $template['name'];
            $description = $template['description'];
            $body = $template['templateBody'];
            $arguments = array_values($template['arguments']);

            $builder->addPrompt(
                static function (array $params = []) use ($body, $arguments): array {
                    $rendered = self::renderTemplate($body, $arguments, $params);

                    return ['user' => $rendered];
                },
                $name,
                title: $name,
                description: $description !== '' ? $description : null,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $argumentDefinitions
     * @param array<string, mixed> $params
     */
    private static function renderTemplate(string $body, array $argumentDefinitions, array $params): string
    {
        $rendered = $body;

        foreach ($argumentDefinitions as $definition) {
            $argName = (string) ($definition['name'] ?? '');
            if ($argName === '') {
                continue;
            }

            $value = $params[$argName] ?? ($definition['default'] ?? '');
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $rendered = str_replace('{{' . $argName . '}}', (string) $value, $rendered);
        }

        return $rendered;
    }
}
