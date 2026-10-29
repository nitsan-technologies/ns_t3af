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

        $pageIds = self::explicitPageIdList($remainder, $params, $arguments);
        if ($pageIds !== null) {
            $arguments['pageIds'] = $pageIds;

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
                $idParams[] = $param;
                continue;
            }

            $stringParams[] = $param;
        }

        $parts = preg_split('/\s+/', $remainder, 2) ?: [];
        $first = $parts[0] ?? '';
        $afterFirst = trim($parts[1] ?? '');

        $bindableIds = array_values(array_filter(
            $idParams,
            static function (array $param): bool {
                $name = (string) ($param['name'] ?? '');

                return in_array($name, self::ID_PARAM_NAMES, true) || ($param['required'] ?? false) === true;
            },
        ));
        if ($first !== '' && preg_match('/^-?\d+$/', $first) === 1 && $bindableIds !== []) {
            if (str_starts_with($first, '-')) {
                throw new \InvalidArgumentException('That id is not valid. Use a positive number.');
            }
            $idName = (string) (self::preferredIdParam($bindableIds)['name'] ?? 'uid');
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
     * A digit list belongs in pageIds when that is the tool's page argument.
     * A tool that already has pageId or uid keeps the existing single-id binding.
     *
     * @param list<array{name?: string, type?: string, required?: bool, default?: string|null}> $params
     * @param array<string, mixed> $arguments
     * @return list<int>|null
     */
    private static function explicitPageIdList(string $remainder, array $params, array $arguments): ?array
    {
        if (array_key_exists('pageIds', $arguments) || preg_match('/^\d+(?:[\s,;]+\d+)*$/', $remainder) !== 1) {
            return null;
        }

        $hasPageIds = false;
        foreach ($params as $param) {
            $name = trim((string) ($param['name'] ?? ''));
            if ($name === 'pageIds') {
                $hasPageIds = true;
            }
            if (in_array($name, self::ID_PARAM_NAMES, true)) {
                return null;
            }
            $type = strtolower((string) ($param['type'] ?? ''));
            $baseType = explode('|', $type)[0];
            if (
                ($param['required'] ?? false) === true
                && ($baseType === 'int' || $baseType === 'integer')
            ) {
                return null;
            }
        }
        if (!$hasPageIds) {
            return null;
        }

        $ids = [];
        foreach (preg_split('/[\s,;]+/', $remainder) ?: [] as $part) {
            $uid = (int) $part;
            if ($uid > 0) {
                $ids[$uid] = $uid;
            }
        }

        return $ids === [] ? null : array_values($ids);
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

    /**
     * A required id wins, then a known page/file id, then any other integer.
     * `rootPageId` is required on training summary; `dataId` is optional and must not take `48`.
     *
     * @param list<array{name?: string, type?: string, required?: bool, default?: string|null}> $params
     * @return array{name?: string, type?: string, required?: bool, default?: string|null}
     */
    private static function preferredIdParam(array $params): array
    {
        $buckets = [[], [], [], []];
        foreach ($params as $param) {
            $name = (string) ($param['name'] ?? '');
            $required = ($param['required'] ?? false) === true;
            $known = in_array($name, self::ID_PARAM_NAMES, true);
            $buckets[$required ? ($known ? 0 : 1) : ($known ? 2 : 3)][] = $param;
        }
        foreach ($buckets as $bucket) {
            if ($bucket !== []) {
                return $bucket[0];
            }
        }

        return $params[0];
    }
}
