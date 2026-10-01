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

namespace NITSAN\NsT3AF\Agent\Service;

use NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService;

/**
 * Maps free-text after a slash command onto tool parameters using the tool schema.
 *
 * End users type `/tool anything here`; JSON and schema binding cover the rest.
 *
 * @internal
 */
final class AgentSlashArgumentBinder
{
    private const SKIP_PARAMS = ['aiProvider', 'workspaceId'];

    private const ID_PARAM_NAMES = [
        'uid',
        'pageId',
        'pid',
        'workspaceVersionUid',
        'parentPageId',
        'target',
        'fileUid',
    ];

    public function __construct(
        private readonly McpToolIntrospectorService $toolIntrospector,
    ) {}

    /**
     * @param array<string, mixed> $arguments Already-parsed args (e.g. JSON body)
     * @return array<string, mixed>
     */
    public function bindForTool(string $toolName, array $arguments, string $remainder): array
    {
        return $this->bind($arguments, $remainder, $this->paramsFor($toolName));
    }

    /**
     * @param array<string, mixed> $arguments Already-parsed args (e.g. JSON body)
     * @param list<array{name?: string, type?: string, required?: bool, default?: string|null}> $params
     * @return array<string, mixed>
     */
    public function bind(array $arguments, string $remainder, array $params): array
    {
        $remainder = trim($remainder);
        if ($remainder === '') {
            return $arguments;
        }

        if ($params === []) {
            return $this->bindLegacyUid($arguments, $remainder);
        }

        $stringParams = [];
        $idParams = [];
        foreach ($params as $param) {
            $name = trim((string) ($param['name'] ?? ''));
            if ($name === '' || in_array($name, self::SKIP_PARAMS, true)) {
                continue;
            }
            if (array_key_exists($name, $arguments)) {
                continue;
            }

            $type = strtolower((string) ($param['type'] ?? 'string'));
            $baseType = explode('|', $type)[0];

            if ($baseType === 'array' || $baseType === 'bool' || $baseType === 'boolean') {
                continue;
            }

            if (
                $baseType === 'int'
                || $baseType === 'integer'
                || in_array($name, self::ID_PARAM_NAMES, true)
            ) {
                if (in_array($name, self::ID_PARAM_NAMES, true)) {
                    $idParams[] = $param;
                }
                continue;
            }

            $stringParams[] = $param;
        }

        $parts = preg_split('/\s+/', $remainder, 2) ?: [];
        $first = $parts[0] ?? '';
        $afterFirst = trim($parts[1] ?? '');

        if ($first !== '' && ctype_digit($first) && $idParams !== []) {
            $idName = (string) ($idParams[0]['name'] ?? 'uid');
            $arguments[$idName] = (int) $first;
            $remainder = $afterFirst;
            if ($remainder === '') {
                return $arguments;
            }
        }

        $fillable = $this->prioritizeStringParams($stringParams);
        if ($fillable === []) {
            return $arguments;
        }

        $firstName = (string) ($fillable[0]['name'] ?? '');
        $secondName = (string) ($fillable[1]['name'] ?? '');

        // tableName + search (and similar): first token → first string, rest → second.
        if (
            $secondName !== ''
            && ($fillable[0]['required'] ?? false) === true
            && ($fillable[1]['required'] ?? false) === true
            && str_contains($remainder, ' ')
        ) {
            $split = preg_split('/\s+/', $remainder, 2) ?: [];
            $arguments[$firstName] = (string) ($split[0] ?? '');
            $arguments[$secondName] = trim((string) ($split[1] ?? ''));

            return $arguments;
        }

        $arguments[$firstName] = $remainder;

        return $arguments;
    }

    /**
     * @return list<array{name?: string, type?: string, required?: bool, default?: string|null}>
     */
    private function paramsFor(string $toolName): array
    {
        if ($toolName === '') {
            return [];
        }

        foreach ($this->toolIntrospector->listTools() as $tool) {
            if ((string) ($tool['name'] ?? '') === $toolName) {
                return is_array($tool['params'] ?? null) ? $tool['params'] : [];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function bindLegacyUid(array $arguments, string $remainder): array
    {
        $parts = preg_split('/\s+/', $remainder) ?: [];
        if (isset($parts[0]) && ctype_digit($parts[0]) && !array_key_exists('uid', $arguments)) {
            $arguments['uid'] = (int) $parts[0];
        }

        return $arguments;
    }

    /**
     * Required strings first, then optional strings with an empty default (search/namePattern).
     *
     * @param list<array{name?: string, type?: string, required?: bool, default?: string|null}> $params
     * @return list<array{name?: string, type?: string, required?: bool, default?: string|null}>
     */
    private function prioritizeStringParams(array $params): array
    {
        $required = [];
        $optionalEmpty = [];
        foreach ($params as $param) {
            if (($param['required'] ?? false) === true) {
                $required[] = $param;
                continue;
            }
            $default = $param['default'] ?? null;
            if ($default === null || $default === '') {
                $optionalEmpty[] = $param;
            }
        }

        return [...$required, ...$optionalEmpty];
    }
}
