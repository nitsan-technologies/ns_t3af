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

/**
 * Fixture-based agent routing eval (record + replay, no live LLM).
 *
 * @internal
 */
final class AgentEvalRunner
{
    public function defaultFixtureDirectory(): string
    {
        return dirname(__DIR__, 3) . '/Tests/Fixtures/AgentEval';
    }

    /**
     * @param array{
     *     id: string,
     *     userMessage?: string,
     *     mocked?: array<string, mixed>,
     *     expect?: array<string, mixed>
     * } $case
     */
    public function record(array $case, ?string $directory = null): string
    {
        $id = trim((string) ($case['id'] ?? ''));
        if ($id === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $id) !== 1) {
            throw new \InvalidArgumentException('Eval fixture id must be alphanumeric (dashes/underscores ok).', 1742200601);
        }

        $directory ??= $this->defaultFixtureDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create eval fixture directory: ' . $directory, 1742200602);
        }

        $payload = [
            'id' => $id,
            'userMessage' => (string) ($case['userMessage'] ?? ''),
            'mocked' => is_array($case['mocked'] ?? null) ? $case['mocked'] : [],
            'expect' => is_array($case['expect'] ?? null) ? $case['expect'] : [],
        ];
        if ($payload['expect'] === [] && $payload['mocked'] !== []) {
            $payload['expect'] = $this->expectFromMocked($payload['mocked']);
        }

        $path = $directory . '/' . $id . '.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException('Cannot write eval fixture: ' . $path, 1742200603);
        }

        return $path;
    }

    /**
     * @return list<array{id: string, path: string, ok: bool, errors: list<string>}>
     */
    public function replay(?string $directory = null): array
    {
        $directory ??= $this->defaultFixtureDirectory();
        if (!is_dir($directory)) {
            throw new \RuntimeException('Eval fixture directory missing: ' . $directory, 1742200604);
        }

        $files = glob($directory . '/*.json') ?: [];
        sort($files);
        $results = [];
        foreach ($files as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                $results[] = [
                    'id' => basename($path, '.json'),
                    'path' => $path,
                    'ok' => false,
                    'errors' => ['Invalid JSON fixture'],
                ];
                continue;
            }
            $errors = $this->assertCase($decoded);
            $results[] = [
                'id' => (string) ($decoded['id'] ?? basename($path, '.json')),
                'path' => $path,
                'ok' => $errors === [],
                'errors' => $errors,
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $case
     * @return list<string>
     */
    public function assertCase(array $case): array
    {
        $errors = [];
        $id = trim((string) ($case['id'] ?? ''));
        if ($id === '') {
            $errors[] = 'Missing id';
        }

        $mocked = is_array($case['mocked'] ?? null) ? $case['mocked'] : [];
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
        if ($expect === []) {
            $errors[] = 'Missing expect block';

            return $errors;
        }

        foreach (['tool', 'routingSource'] as $key) {
            if (!array_key_exists($key, $expect)) {
                continue;
            }
            $expected = (string) $expect[$key];
            $actual = (string) ($mocked[$key] ?? '');
            if ($actual !== $expected) {
                $errors[] = sprintf('%s: expected "%s", got "%s"', $key, $expected, $actual);
            }
        }

        if (array_key_exists('fieldKeys', $expect)) {
            $expectedKeys = $this->normalizeStringList($expect['fieldKeys']);
            $actualKeys = $this->normalizeStringList($mocked['fieldKeys'] ?? ($mocked['arguments']['fieldKeys'] ?? []));
            if ($actualKeys !== $expectedKeys) {
                $errors[] = sprintf(
                    'fieldKeys: expected [%s], got [%s]',
                    implode(', ', $expectedKeys),
                    implode(', ', $actualKeys),
                );
            }
        }

        if (array_key_exists('variantsComplete', $expect)) {
            $expectedComplete = (bool) $expect['variantsComplete'];
            $actualComplete = (bool) ($mocked['variantsComplete'] ?? false);
            if ($actualComplete !== $expectedComplete) {
                $errors[] = sprintf(
                    'variantsComplete: expected %s, got %s',
                    $expectedComplete ? 'true' : 'false',
                    $actualComplete ? 'true' : 'false',
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $mocked
     * @return array<string, mixed>
     */
    public function expectFromMocked(array $mocked): array
    {
        $expect = [];
        if (isset($mocked['tool'])) {
            $expect['tool'] = (string) $mocked['tool'];
        }
        if (isset($mocked['routingSource'])) {
            $expect['routingSource'] = (string) $mocked['routingSource'];
        }
        $fieldKeys = $mocked['fieldKeys'] ?? ($mocked['arguments']['fieldKeys'] ?? null);
        if ($fieldKeys !== null) {
            $expect['fieldKeys'] = $this->normalizeStringList($fieldKeys);
        }
        if (array_key_exists('variantsComplete', $mocked)) {
            $expect['variantsComplete'] = (bool) $mocked['variantsComplete'];
        }

        return $expect;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $parts = array_map(static fn(string $part): string => trim($part), explode(',', $value));

            return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        }
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $item) {
            if (is_scalar($item) && (string) $item !== '') {
                $list[] = (string) $item;
            }
        }

        return $list;
    }
}
