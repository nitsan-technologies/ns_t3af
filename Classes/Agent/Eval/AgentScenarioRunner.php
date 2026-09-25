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

use NITSAN\NsT3AF\Agent\Contract\AgentActionCatalogInterface;
use NITSAN\NsT3AF\Agent\Contract\AgentTurnRunnerInterface;
use NITSAN\NsT3AF\Agent\Service\AgentConversationRecorder;
use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Runs eval scenarios through the real agent loop with a real provider.
 *
 * - Each turn goes through the same runner as the agent window (tool selection, budgets,
 *   prompts), with the page and module of the scenario as context.
 * - Nothing is written: write tools only prepare cards. An "applied" step simulates the
 *   editor's confirmation (the card is marked applied and the result is added), so the
 *   turn after a confirmation is tested too.
 * - Turns cost provider tokens / credits like normal agent turns.
 *
 * @internal
 */
final readonly class AgentScenarioRunner
{
    public function __construct(
        private AgentTurnRunnerInterface $turnRunner,
        private AgentConversationRecorder $recorder,
        private AgentActionCatalogInterface $actionCatalog,
        private AgentScenarioJudge $judge,
    ) {}

    public function defaultDirectory(): string
    {
        return dirname(__DIR__, 3) . '/Resources/Private/Agent/Eval/Scenarios';
    }

    /**
     * @param list<string> $onlyIds scenario ids (or id prefixes like "05"); empty = all
     * @return list<array<string, mixed>>
     */
    public function load(?string $directory = null, array $onlyIds = []): array
    {
        $directory ??= $this->defaultDirectory();
        if (!is_dir($directory)) {
            throw new \RuntimeException('Scenario directory missing: ' . $directory, 1790330001);
        }
        $files = glob($directory . '/*.json') ?: [];
        sort($files);

        $scenarios = [];
        foreach ($files as $file) {
            $scenario = json_decode((string) file_get_contents($file), true);
            if (!is_array($scenario) || !is_array($scenario['turns'] ?? null) || trim((string) ($scenario['id'] ?? '')) === '') {
                throw new \RuntimeException('Invalid scenario file: ' . $file, 1790330002);
            }
            if ($onlyIds !== [] && !self::matchesAny((string) $scenario['id'], $onlyIds)) {
                continue;
            }
            $scenarios[] = $scenario;
        }

        return $scenarios;
    }

    /**
     * The module a scenario runs in (page module unless it says otherwise).
     *
     * @param array<string, mixed> $scenario
     */
    public static function moduleOf(array $scenario): string
    {
        $module = trim((string) ($scenario['module'] ?? ''));

        return $module !== '' ? $module : 'web_layout';
    }

    /**
     * @param array<string, mixed> $scenario
     * @param array<string, mixed> $context the presented context of the page and module (as the agent window sends it)
     * @return array{
     *     id: string,
     *     title: string,
     *     provider: string,
     *     status: string,
     *     seconds: float,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     turns: list<array<string, mixed>>
     * }
     */
    public function run(array $scenario, string $provider, array $context, BackendUserAuthentication $user): array
    {
        $scenario = self::withPlaceholders($scenario, ['{{pageId}}' => (int) ($context['pageId'] ?? 0)]);
        $report = [
            'id' => (string) $scenario['id'],
            'title' => (string) ($scenario['title'] ?? ''),
            'provider' => $provider,
            'status' => 'pass',
            'seconds' => 0.0,
            'errors' => [],
            'warnings' => [],
            'turns' => [],
        ];

        $requires = array_values(array_filter(is_array($scenario['requires'] ?? null) ? $scenario['requires'] : [], 'is_string'));
        if ($requires !== [] && array_intersect($requires, $this->permittedToolNames()) === []) {
            $report['status'] = 'skip';
            $report['warnings'][] = sprintf('None of the tools [%s] is installed or permitted.', implode(', ', $requires));

            return $report;
        }

        $history = [];
        $previousHadCard = false;
        foreach ($scenario['turns'] as $index => $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $label = sprintf('Turn %d', $index + 1);
            if (($turn['skipIfCard'] ?? false) === true && $previousHadCard) {
                $report['turns'][] = ['label' => $label, 'status' => 'skip', 'message' => (string) ($turn['message'] ?? '')];
                continue;
            }

            $continuation = false;
            if (is_array($turn['applied'] ?? null)) {
                $applied = $this->simulateApply($history, $turn['applied']);
                if ($applied === null) {
                    $report['errors'][] = $label . ': there was no prepared change to apply.';
                    $report['status'] = 'fail';
                    break;
                }
                [$history, $message] = $applied;
                $continuation = true;
            } else {
                $message = (string) ($turn['message'] ?? '');
            }

            $observation = $this->runTurn($message, $history, $context, $provider, $user);
            $errors = $this->judge->judge(is_array($turn['expect'] ?? null) ? $turn['expect'] : [], $observation);
            $lenient = ($turn['lenient'] ?? false) === true;

            $report['seconds'] += $observation['seconds'];
            $report['turns'][] = [
                'label' => $label,
                'status' => $errors === [] ? 'pass' : ($lenient ? 'warn' : 'fail'),
                'message' => $continuation ? '(editor applied the change)' : $message,
                'seconds' => round($observation['seconds'], 1),
                'modelRequests' => $observation['modelRequests'],
                'toolCalls' => array_map(
                    static fn(array $call): string => $call['outcome'] === 'executed' ? $call['tool'] : $call['tool'] . ' (' . $call['outcome'] . ')',
                    $observation['toolCalls'],
                ),
                'cards' => array_values(array_filter(array_map(
                    static fn(array $message): string => in_array($message['meta']['type'] ?? '', ['inline_draft', 'suggestions', 'clarification'], true) ? (string) $message['meta']['type'] : '',
                    $observation['messages'],
                ))),
                'pauseReason' => $observation['pauseReason'],
                'reply' => mb_substr(AgentScenarioJudge::finalReply($observation['messages']), 0, 300),
                'errors' => $errors,
                'arguments' => array_map(static fn(array $call): array => ['tool' => $call['tool'], 'arguments' => $call['arguments']], $observation['toolCalls']),
            ];
            foreach ($errors as $error) {
                $report[$lenient ? 'warnings' : 'errors'][] = $label . ': ' . $error;
            }

            $history[] = [
                'role' => 'user',
                'content' => $message,
                'meta' => ['hidden' => $continuation, 'type' => $continuation ? 'continuation' : 'message'],
            ];
            $history = [...$history, ...$observation['messages']];
            $previousHadCard = $this->lastCardIndex($observation['messages']) !== null;

            if ($errors !== [] && !$lenient) {
                // Later turns build on this one.
                $report['status'] = 'fail';
                break;
            }
        }

        if ($report['errors'] !== []) {
            $report['status'] = 'fail';
        } elseif ($report['warnings'] !== []) {
            $report['status'] = 'warn';
        }
        $report['seconds'] = round($report['seconds'], 1);

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed> $context
     * @return array{
     *     messages: list<array<string, mixed>>,
     *     paused: bool,
     *     pauseReason: string|null,
     *     toolCalls: list<array{tool: string, arguments: array<mixed>, outcome: string}>,
     *     modelRequests: int,
     *     seconds: float,
     *     error: string|null
     * }
     */
    private function runTurn(string $message, array $history, array $context, string $provider, BackendUserAuthentication $user): array
    {
        $toolCalls = [];
        $modelRequests = 0;
        $emit = static function (string $event, array $payload) use (&$toolCalls, &$modelRequests): void {
            if ($event === 'tool_call') {
                $toolCalls[] = [
                    'tool' => (string) ($payload['tool'] ?? ''),
                    'arguments' => is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [],
                    'outcome' => (string) ($payload['outcome'] ?? ''),
                ];
            } elseif ($event === 'model_request') {
                ++$modelRequests;
            }
        };

        $started = microtime(true);
        $result = ['messages' => [], 'paused' => false, 'pauseReason' => null];
        $error = null;
        try {
            $result = $this->turnRunner->runTurn(
                $message,
                $history,
                $context,
                ['provider' => $provider, 'message' => $message],
                $user,
                bin2hex(random_bytes(16)),
                $emit,
            );
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        return [
            'messages' => $result['messages'],
            'paused' => $result['paused'],
            'pauseReason' => $result['pauseReason'],
            'toolCalls' => $toolCalls,
            'modelRequests' => $modelRequests,
            'seconds' => microtime(true) - $started,
            'error' => $error,
        ];
    }

    /**
     * Marks the last prepared change as applied and returns the continuation message the
     * agent window would send.
     *
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed> $applied {table, uid, values}
     * @return array{0: list<array<string, mixed>>, 1: string}|null
     */
    private function simulateApply(array $history, array $applied): ?array
    {
        $index = $this->lastCardIndex($history);
        if ($index === null) {
            return null;
        }
        $meta = $history[$index]['meta'];
        $draftId = (string) ($meta['draft']['draftId'] ?? $meta['draftId'] ?? '');
        $label = (string) ($meta['editorLabel'] ?? $meta['draft']['editorLabel'] ?? $meta['tool'] ?? '');
        $table = (string) ($applied['table'] ?? '');
        $uid = (int) ($applied['uid'] ?? 0);
        $values = is_array($applied['values'] ?? null) ? $applied['values'] : [];
        $title = (string) ($values['title'] ?? $values['header'] ?? '');
        $summary = trim(sprintf('Created %s uid %d%s', $table, $uid, $title !== '' ? ' "' . $title . '"' : ''));

        $history = $this->recorder->applied($history, $draftId, [
            'readback' => [['table' => $table, 'uid' => $uid, 'values' => $values]],
            'appliedCount' => max(1, count($values)),
            'totalCount' => max(1, count($values)),
            'tool' => (string) ($meta['tool'] ?? ''),
            'presentation' => ['content' => $summary, 'success' => true, 'details' => ['uid' => $uid, 'table' => $table, ...$values]],
        ], ['message' => '']);

        return [$history, AgentPromptBuilder::continuationMessage(
            ['outcome' => 'applied', 'label' => $label, 'result' => $summary],
            $history,
        )];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function lastCardIndex(array $messages): ?int
    {
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $meta = is_array($messages[$i]['meta'] ?? null) ? $messages[$i]['meta'] : [];
            $type = (string) ($meta['type'] ?? '');
            $open = ($meta['draft']['applied'] ?? $meta['applied'] ?? false) !== true;
            if (in_array($type, ['inline_draft', 'suggestions'], true) && $open) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function permittedToolNames(): array
    {
        return array_values(array_map(
            static fn(array $tool): string => (string) ($tool['name'] ?? ''),
            $this->actionCatalog->buildCatalog()['executable'],
        ));
    }

    /**
     * @param list<string> $ids
     */
    private static function matchesAny(string $id, array $ids): bool
    {
        foreach ($ids as $wanted) {
            $wanted = trim($wanted);
            if ($wanted !== '' && str_starts_with($id, $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "{{pageId}}" in messages and expectations; a value that is only the placeholder becomes a number.
     *
     * @param array<mixed> $data
     * @param array<string, int> $values
     * @return array<mixed>
     */
    public static function withPlaceholders(array $data, array $values): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withPlaceholders($value, $values);
            } elseif (is_string($value)) {
                $data[$key] = isset($values[$value]) ? $values[$value] : strtr($value, array_map('strval', $values));
            }
        }

        return $data;
    }
}
