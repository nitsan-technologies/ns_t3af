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

namespace NITSAN\NsT3AF\Api;

/**
 * Tool schema passed to the provider for function-calling turns.
 *
 * @api
 */
final readonly class AiToolDefinition
{
    /**
     * @param array<string, mixed> $parameters JSON-schema-style parameters object
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = ['type' => 'object', 'properties' => new \stdClass()],
    ) {}

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function toProviderShape(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
        ];
    }
}
