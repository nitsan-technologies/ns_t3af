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

namespace NITSAN\NsT3AF\Agent\Eval;

/**
 * Checks one agent turn of an eval scenario against its expectations.
 *
 * Every turn also gets the default checks that catch the problems editors notice most:
 * no answer (loop / step limit / budget pause), the same read over and over, errors, and
 * slow turns.
 *
 * Pure: it only looks at what the turn produced, so it is unit tested without a provider.
 *
 * @internal
 */
final class AgentScenarioJudge
{
    /** Pauses that mean "the editor got no answer". */
    public const DEAD_END_PAUSES = ['loop_limit', 'repeated_reads', 'read_budget', 'write_budget'];

    public const DEFAULT_MAX_MODEL_REQUESTS = 6;

    public const DEFAULT_MAX_SECONDS = 90.0;

    public const DEFAULT_MAX_REPEATED_CALLS = 1;

    /** Message types that are a prepared change for the editor to review. */
    private const CARD_TYPES = ['inline_draft', 'suggestions'];

    /**
     * @param array<string, mixed> $expect
     * @param array{
     *     messages: list<array<string, mixed>>,
     *     paused: bool,
     *     pauseReason: string|null,
     *     toolCalls: list<array{tool: string, arguments: array<mixed>, outcome: string}>,
     *     modelRequests: int,
     *     seconds: float,
     *     error: string|null
     * } $turn
     * @return list<string> what went wrong, in plain words; empty when the turn passed
     */
    public function judge(array $expect, array $turn): array
    {
        if ($turn['error'] !== null) {
            return ['The turn failed: ' . $turn['error']];
        }

        $errors = [];
        $called = array_column($turn['toolCalls'], 'tool');
        $ran = array_column(array_filter($turn['toolCalls'], static fn(array $call): bool => $call['outcome'] === 'executed'), 'tool');

        // Default checks.
        if ($turn['paused'] && in_array((string) $turn['pauseReason'], self::DEAD_END_PAUSES, true)) {
            $errors[] = sprintf('No answer: the turn stopped with "%s".', $turn['pauseReason']);
        }
        foreach ($turn['messages'] as $message) {
            $type = (string) ($message['meta']['type'] ?? '');
            if ($type === 'error') {
                $errors[] = 'Error message: ' . self::shorten((string) ($message['content'] ?? ''));
            }
        }
        $repeated = count(array_filter($turn['toolCalls'], static fn(array $call): bool => in_array($call['outcome'], ['repeated', 'stopped'], true)));
        $maxRepeated = (int) ($expect['maxRepeatedCalls'] ?? self::DEFAULT_MAX_REPEATED_CALLS);
        if ($repeated > $maxRepeated) {
            $errors[] = sprintf('The same read was repeated %d times (allowed: %d).', $repeated, $maxRepeated);
        }
        $maxRequests = (int) ($expect['maxModelRequests'] ?? self::DEFAULT_MAX_MODEL_REQUESTS);
        if ($turn['modelRequests'] > $maxRequests) {
            $errors[] = sprintf('%d model requests (allowed: %d).', $turn['modelRequests'], $maxRequests);
        }
        $maxSeconds = (float) ($expect['maxSeconds'] ?? self::DEFAULT_MAX_SECONDS);
        if ($turn['seconds'] > $maxSeconds) {
            $errors[] = sprintf('Took %.1f s (allowed: %.0f s).', $turn['seconds'], $maxSeconds);
        }

        // Scenario expectations.
        $anyTool = self::stringList($expect['anyTool'] ?? []);
        if ($anyTool !== [] && array_intersect($anyTool, $ran) === []) {
            $errors[] = sprintf('Expected one of [%s], ran [%s].', implode(', ', $anyTool), implode(', ', array_unique($ran)));
        }
        $forbidden = array_values(array_intersect(self::stringList($expect['forbidTools'] ?? []), $called));
        if ($forbidden !== []) {
            $errors[] = sprintf('Must not call [%s].', implode(', ', array_unique($forbidden)));
        }
        if (($expect['noTools'] ?? false) === true && $ran !== []) {
            $errors[] = sprintf('Expected an answer without tools, ran [%s].', implode(', ', array_unique($ran)));
        }
        if (isset($expect['maxToolCalls']) && count($turn['toolCalls']) > (int) $expect['maxToolCalls']) {
            $errors[] = sprintf('%d tool calls (allowed: %d).', count($turn['toolCalls']), (int) $expect['maxToolCalls']);
        }

        // What the agent said instead (e.g. "no tool calling", governance limit), for the report.
        $said = self::agentNote($turn['messages']);
        $saidSuffix = $said !== '' ? sprintf(' The agent said: "%s"', $said) : '';

        $hasCard = self::hasMessageType($turn['messages'], self::CARD_TYPES);
        $hasClarification = self::hasMessageType($turn['messages'], ['clarification']);
        $card = $expect['card'] ?? null;
        if ($card === true && !$hasCard) {
            $errors[] = 'Expected a prepared change to review, got none.' . $saidSuffix;
        } elseif ($card === 'orClarification' && !$hasCard && !$hasClarification) {
            $errors[] = 'Expected a prepared change or a question with choices, got neither.' . $saidSuffix;
        } elseif ($card === false && $hasCard) {
            $errors[] = 'Expected no prepared change, got one.';
        }

        if (($expect['reply'] ?? false) === true && self::finalReply($turn['messages']) === '') {
            $errors[] = 'Expected a written answer, got none.' . $saidSuffix;
        }

        $contains = is_array($expect['argumentsContain'] ?? null) ? $expect['argumentsContain'] : [];
        if ($contains !== []) {
            $candidates = array_filter(
                $turn['toolCalls'],
                static fn(array $call): bool => $call['outcome'] === 'executed' && ($anyTool === [] || in_array($call['tool'], $anyTool, true)),
            );
            foreach ($contains as $key => $value) {
                $found = false;
                foreach ($candidates as $call) {
                    if (self::containsKeyValue($call['arguments'], (string) $key, $value)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $errors[] = sprintf('Expected a call with %s = %s.', $key, is_scalar($value) ? (string) $value : json_encode($value));
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function finalReply(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            if (($messages[$i]['meta']['type'] ?? '') === 'nl_reply') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    /**
     * The last info / blocked / budget message of the turn, shortened.
     *
     * @param list<array<string, mixed>> $messages
     */
    private static function agentNote(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $type = (string) ($messages[$i]['meta']['type'] ?? '');
            if (in_array($type, ['info', 'governance_blocked', 'budget_exceeded', 'locked'], true)) {
                return self::shorten(trim((string) ($messages[$i]['content'] ?? '')));
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<string> $types
     */
    private static function hasMessageType(array $messages, array $types): bool
    {
        foreach ($messages as $message) {
            if (in_array((string) ($message['meta']['type'] ?? ''), $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the arguments contain the key with the value at any depth (numbers compare loosely).
     *
     * @param array<mixed> $arguments
     */
    public static function containsKeyValue(array $arguments, string $key, mixed $value): bool
    {
        foreach ($arguments as $name => $argument) {
            if ((string) $name === $key && is_scalar($argument) && is_scalar($value) && (string) $argument === (string) $value) {
                return true;
            }
            if (is_string($argument) && str_starts_with(ltrim($argument), '{')) {
                $decoded = json_decode($argument, true);
                if (is_array($decoded) && self::containsKeyValue($decoded, $key, $value)) {
                    return true;
                }
            }
            if (is_array($argument) && self::containsKeyValue($argument, $key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }

    private static function shorten(string $text): string
    {
        return mb_strlen($text) > 160 ? mb_substr($text, 0, 159) . '…' : $text;
    }
}
