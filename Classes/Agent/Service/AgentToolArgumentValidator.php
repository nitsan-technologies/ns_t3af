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

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Checks the arguments of a model tool call against the tool's JSON schema before anything runs.
 *
 * Harmless mismatches that models often produce are fixed first ("49" for a number,
 * "true" for a boolean, 12 or an object for a string, null for an optional value). What is still
 * invalid is returned as messages for the model, which then corrects the call.
 *
 * @internal
 */
final readonly class AgentToolArgumentValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $arguments
     * @return array{arguments: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $schema, array $arguments): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? array_values(array_map('strval', $schema['required'])) : [];
        $arguments = $this->coerce($properties, $required, $arguments);

        if ($properties === [] && $required === []) {
            return ['arguments' => $arguments, 'errors' => []];
        }

        $schema['type'] = 'object';
        $schema['properties'] = $properties === [] ? new \stdClass() : $properties;

        try {
            $validator = new Validator();
            $validator->setMaxErrors(10);
            $result = $validator->validate(
                json_decode(json_encode($arguments === [] ? new \stdClass() : $arguments, JSON_THROW_ON_ERROR)),
                json_encode($schema, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            // A schema the validator cannot read must not block the tool; the tool checks its input itself.
            return ['arguments' => $arguments, 'errors' => []];
        }

        if ($result->isValid()) {
            return ['arguments' => $arguments, 'errors' => []];
        }

        $errors = [];
        $error = $result->error();
        if ($error !== null) {
            foreach ((new ErrorFormatter())->formatKeyed($error) as $path => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ($path === '/' ? '' : ltrim((string) $path, '/') . ': ') . $message;
                }
            }
        }

        return ['arguments' => $arguments, 'errors' => $errors !== [] ? $errors : ['The arguments do not match the tool parameters.']];
    }

    /**
     * @param array<mixed> $properties
     * @param list<string> $required
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function coerce(array $properties, array $required, array $arguments): array
    {
        foreach ($arguments as $name => $value) {
            if ($value === null && !in_array($name, $required, true)) {
                unset($arguments[$name]);
                continue;
            }
            $definition = $properties[$name] ?? null;
            if (!is_array($definition) || !is_string($definition['type'] ?? null)) {
                continue;
            }
            $arguments[$name] = $this->coerceValue((string) $definition['type'], $value);
        }

        return $arguments;
    }

    private function coerceValue(string $type, mixed $value): mixed
    {
        return match (true) {
            ($type === 'number' || $type === 'integer') && is_string($value) && is_numeric(trim($value))
                => str_contains($value, '.') ? (float) $value : (int) $value,
            $type === 'boolean' && is_string($value) && in_array(strtolower(trim($value)), ['true', '1', 'yes'], true) => true,
            $type === 'boolean' && is_string($value) && in_array(strtolower(trim($value)), ['false', '0', 'no'], true) => false,
            $type === 'boolean' && is_int($value) && ($value === 0 || $value === 1) => $value === 1,
            $type === 'string' && (is_int($value) || is_float($value)) => (string) $value,
            // e.g. write_table "data": a JSON string parameter the model sent as an object
            $type === 'string' && is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $type === 'array' && is_string($value) && str_starts_with(trim($value), '[') => $this->decodeList($value),
            default => $value,
        };
    }

    private function decodeList(string $value): mixed
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }
}
