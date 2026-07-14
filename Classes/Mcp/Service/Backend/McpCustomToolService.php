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

/**
 * CRUD facade for user-defined MCP custom tools (backend UI).
 */
readonly class McpCustomToolService
{
    public function __construct(
        private McpCustomToolRepository $customToolRepository,
    ) {}

    /**
     * @return list<array{
     *     uid: int,
     *     toolKey: string,
     *     label: string,
     *     description: string,
     *     handlerType: string,
     *     handlerValue: string,
     *     parameters: list<array<string, mixed>>,
     *     hidden: bool,
     *     deleted: bool,
     *     crdate: int,
     *     tstamp: int
     * }>
     */
    public function listTools(): array
    {
        return $this->customToolRepository->findVisible();
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    public function create(
        string $label,
        string $description,
        string $handlerType,
        string $handlerValue,
        array $parameters,
    ): int {
        $label = trim($label);
        $handlerValue = trim($handlerValue);
        if ($label === '' || $handlerValue === '') {
            throw new \InvalidArgumentException('Label and handler configuration are required');
        }

        $handlerType = $this->normalizeHandlerType($handlerType);
        $toolKey = $this->buildUniqueToolKey($label);

        return $this->customToolRepository->insert(
            $toolKey,
            $label,
            trim($description),
            $handlerType,
            $handlerValue,
            $parameters,
        );
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    public function update(
        int $uid,
        string $label,
        string $description,
        string $handlerType,
        string $handlerValue,
        array $parameters,
    ): void {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Invalid custom tool uid');
        }

        if ($this->customToolRepository->findByUid($uid) === null) {
            throw new \InvalidArgumentException('Custom tool not found');
        }

        $label = trim($label);
        $handlerValue = trim($handlerValue);
        if ($label === '' || $handlerValue === '') {
            throw new \InvalidArgumentException('Label and handler configuration are required');
        }

        $this->customToolRepository->update(
            $uid,
            $label,
            trim($description),
            $this->normalizeHandlerType($handlerType),
            $handlerValue,
            $parameters,
        );
    }

    public function delete(int $uid): void
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Invalid custom tool uid');
        }

        $this->customToolRepository->softDelete($uid);
    }

    public function countVisible(): int
    {
        return $this->customToolRepository->countVisible();
    }

    private function normalizeHandlerType(string $handlerType): string
    {
        return match (strtolower(trim($handlerType))) {
            'rest', 'webhook' => strtolower(trim($handlerType)),
            default => 'php',
        };
    }

    private function buildUniqueToolKey(string $label): string
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? '');
        $base = trim($normalized, '_');
        if ($base === '') {
            $base = 'custom_tool';
        }

        $candidate = $base;
        $suffix = 2;
        while ($this->customToolRepository->findByToolKey($candidate) !== null) {
            $candidate = $base . '_' . $suffix;
            ++$suffix;
        }

        return $candidate;
    }
}
