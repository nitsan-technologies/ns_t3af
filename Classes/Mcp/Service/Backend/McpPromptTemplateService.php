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

namespace NITSAN\NsT3AF\Mcp\Service\Backend;

use const JSON_THROW_ON_ERROR;

use NITSAN\NsT3AF\Mcp\Configuration\McpDefaultPromptTemplateRegistry;

/**
 * CRUD facade for MCP prompt templates (backend UI + MCP prompt protocol).
 */
readonly class McpPromptTemplateService
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        private McpPromptTemplateRepository $promptTemplateRepository,
        private McpDefaultPromptTemplateRegistry $defaultPromptTemplateRegistry,
    ) {}

    /**
     * @return list<array{
     *     uid: int,
     *     name: string,
     *     description: string,
     *     templateBody: string,
     *     arguments: list<array<string, mixed>>,
     *     argumentsJson: string,
     *     isBuiltin: bool,
     *     hidden: bool,
     *     deleted: bool,
     *     crdate: int,
     *     tstamp: int
     * }>
     */
    public function listTemplates(): array
    {
        $this->ensureDefaults();

        return array_map(
            fn(array $row): array => $this->enrichRow($row),
            $this->promptTemplateRepository->findVisible(),
        );
    }

    /**
     * Inserts missing built-in prompt templates (idempotent).
     */
    public function ensureDefaults(): void
    {
        $this->pruneRetiredBuiltins();

        foreach ($this->defaultPromptTemplateRegistry->getDefaults() as $row) {
            if ($this->promptTemplateRepository->findByName($row['name']) !== null) {
                continue;
            }

            $this->promptTemplateRepository->insert(
                $row['name'],
                $row['description'],
                $row['templateBody'],
                $row['arguments'],
            );
        }
    }

    private function pruneRetiredBuiltins(): void
    {
        foreach ($this->defaultPromptTemplateRegistry->getRetiredBuiltinNames() as $name) {
            $existing = $this->promptTemplateRepository->findByName($name);
            if ($existing === null) {
                continue;
            }

            $this->promptTemplateRepository->softDelete($existing['uid']);
        }
    }

    public function findByUid(int $uid): ?array
    {
        foreach ($this->listTemplates() as $row) {
            if ($row['uid'] === $uid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $arguments
     */
    public function create(
        string $name,
        string $description,
        string $templateBody,
        array $arguments,
    ): int {
        $this->assertValidName($name);

        if ($this->defaultPromptTemplateRegistry->isBuiltinName($name)) {
            throw new \InvalidArgumentException('This name is reserved for a built-in prompt template: ' . $name);
        }

        $existing = $this->promptTemplateRepository->findAnyByName($name);
        if ($existing !== null) {
            if ($existing['deleted']) {
                $this->assertValidArguments($arguments);
                $this->promptTemplateRepository->restore($existing['uid'], $description, $templateBody, $arguments);

                return $existing['uid'];
            }

            throw new \InvalidArgumentException('Prompt name already exists: ' . $name);
        }

        $this->assertValidArguments($arguments);

        return $this->promptTemplateRepository->insert(
            $name,
            $description,
            $templateBody,
            $arguments,
        );
    }

    /**
     * @param list<array<string, mixed>> $arguments
     */
    public function update(
        int $uid,
        string $name,
        string $description,
        string $templateBody,
        array $arguments,
    ): void {
        $existing = $this->promptTemplateRepository->findByUid($uid);
        if ($existing === null) {
            throw new \InvalidArgumentException('Prompt template not found.');
        }

        if ($this->defaultPromptTemplateRegistry->isBuiltinName($existing['name'])) {
            throw new \InvalidArgumentException('Built-in prompt templates cannot be edited. Duplicate it as a custom template instead.');
        }

        $this->assertValidName($name);

        if ($name !== $existing['name'] && $this->defaultPromptTemplateRegistry->isBuiltinName($name)) {
            throw new \InvalidArgumentException('This name is reserved for a built-in prompt template: ' . $name);
        }

        $duplicate = $this->promptTemplateRepository->findByName($name);
        if ($duplicate !== null && $duplicate['uid'] !== $uid) {
            throw new \InvalidArgumentException('Prompt name already exists: ' . $name);
        }

        $this->assertValidArguments($arguments);

        $this->promptTemplateRepository->update(
            $uid,
            $name,
            $description,
            $templateBody,
            $arguments,
        );
    }

    public function delete(int $uid): void
    {
        $existing = $this->promptTemplateRepository->findByUid($uid);
        if ($existing === null) {
            throw new \InvalidArgumentException('Prompt template not found.');
        }

        if ($this->defaultPromptTemplateRegistry->isBuiltinName($existing['name'])) {
            throw new \InvalidArgumentException('Built-in prompt templates cannot be deleted.');
        }

        $this->promptTemplateRepository->softDelete($uid);
    }

    public function countVisible(): int
    {
        return $this->promptTemplateRepository->countVisible();
    }

    private function assertValidName(string $name): void
    {
        if ($name === '' || !preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(
                'Prompt name must be snake_case (lowercase letters, digits, underscores; start with a letter).',
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $arguments
     */
    private function assertValidArguments(array $arguments): void
    {
        foreach ($arguments as $index => $argument) {
            $argName = (string) ($argument['name'] ?? '');
            if ($argName === '' || !preg_match(self::NAME_PATTERN, $argName)) {
                throw new \InvalidArgumentException(
                    'Argument #' . ($index + 1) . ' must have a valid snake_case name.',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichRow(array $row): array
    {
        $arguments = is_array($row['arguments'] ?? null) ? $row['arguments'] : [];

        return array_merge($row, [
            'arguments' => $arguments,
            'argumentsJson' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            'isBuiltin' => $this->defaultPromptTemplateRegistry->isBuiltinName((string) ($row['name'] ?? '')),
        ]);
    }
}
