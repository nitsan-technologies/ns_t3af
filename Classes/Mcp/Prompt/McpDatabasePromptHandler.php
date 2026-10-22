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

namespace NITSAN\NsT3AF\Mcp\Prompt;

use Mcp\Exception\PromptGetException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;

/**
 * Renders a DB-backed MCP prompt template with named arguments from prompts/get.
 *
 * @internal
 */
final readonly class McpDatabasePromptHandler implements PromptHandlerInterface
{
    private const PAGE_UID_ARGUMENTS = ['pageId', 'page_id', 'pid'];

    /**
     * @param list<array<string, mixed>> $argumentDefinitions
     */
    public function __construct(
        private string $templateBody,
        private array $argumentDefinitions,
        private RecordService $recordService,
    ) {}

    public function get(array $arguments, ClientGateway $gateway): mixed
    {
        foreach ($this->argumentDefinitions as $definition) {
            $name = (string) ($definition['name'] ?? '');
            if ($name === '' || !($definition['required'] ?? false)) {
                continue;
            }
            $value = $arguments[$name] ?? null;
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                throw new PromptGetException(sprintf('Argument "%s" is required.', $name));
            }
        }

        $this->assertPageUidArgumentsValid($arguments);

        $rendered = McpDynamicPromptRegistrar::renderTemplateBody(
            $this->templateBody,
            $this->argumentDefinitions,
            $arguments,
        );

        return ['user' => $rendered];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertPageUidArgumentsValid(array $arguments): void
    {
        foreach ($this->argumentDefinitions as $definition) {
            $name = (string) ($definition['name'] ?? '');
            if ($name === '' || !in_array($name, self::PAGE_UID_ARGUMENTS, true)) {
                continue;
            }
            if (!array_key_exists($name, $arguments)) {
                continue;
            }
            $raw = $arguments[$name];
            if ($raw === null || $raw === '') {
                continue;
            }
            if (is_int($raw)) {
                $raw = (string) $raw;
            }
            if (!is_string($raw) || !ctype_digit($raw) || (int) $raw <= 0) {
                throw new PromptGetException(sprintf('Argument "%s" must be a positive page UID.', $name));
            }
            $uid = (int) $raw;
            if (!$this->recordService->parentPageExists($uid)) {
                throw new PromptGetException(sprintf('Page %d was not found or is not accessible.', $uid));
            }
        }
    }
}
