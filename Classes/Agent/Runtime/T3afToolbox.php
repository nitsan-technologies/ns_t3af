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

namespace NITSAN\NsT3AF\Agent\Runtime;

use NITSAN\NsT3AF\Api\AiToolDefinition;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Tool\Agent\AskClarificationTool;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The editor's permitted MCP tools for one agent turn, as a Symfony AI toolbox.
 *
 * The model starts with the core set ({@see \NITSAN\NsT3AF\Agent\Service\AgentCoreToolSet})
 * plus {@see self::FIND_TOOLS}, which searches all tools the editor may use and adds the
 * matches for the next round ({@see GovernedPlatform} re-reads the tool list every round).
 *
 * Execution goes through the tool turn executor, which enforces AI Access, previews writes
 * as drafts and renders the chat message. This class adds the per-turn rules:
 *
 * - only tools of the editor's catalog can run; anything else is answered with "not available",
 * - arguments are checked against the tool's JSON schema first; errors go back to the model,
 * - read and write budgets per turn,
 * - after a message that needs the editor (draft review, blocked, budget), the remaining
 *   calls are skipped and {@see AgentTurnState::pause()} ends the loop.
 *
 * Tools with an unknown severity count as writes.
 *
 * @internal
 */
final class T3afToolbox implements ToolboxInterface
{
    public const FIND_TOOLS = 'find_tools';

    public const ASK_CLARIFICATION = 'ask_clarification';

    public const MAX_TOOL_SEARCHES = 3;

    private const FOUND_TOOLS_LIMIT = 5;

    /** @var array<string, AiToolDefinition> */
    private array $definitions = [];

    /** @var array<string, array<string, mixed>> the editor's whole executable catalog */
    private array $catalog = [];

    private int $searches = 0;

    /**
     * @param list<AiToolDefinition> $definitions tools offered at the start of the turn
     * @param list<array<string, mixed>> $catalog all executable catalog entries (searchable)
     * @param array<string, string> $severities tool name → ToolSeverity value
     */
    public function __construct(
        array $definitions,
        array $catalog,
        private readonly array $severities,
        private readonly AgentTurnState $state,
        private readonly AgentToolRuntime $runtime,
    ) {
        foreach ($catalog as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name !== '' && $name !== self::FIND_TOOLS) {
                $this->catalog[$name] = $entry;
            }
        }
        foreach ($definitions as $definition) {
            if (isset($this->catalog[$definition->name])) {
                $this->definitions[$definition->name] = $definition;
            }
        }
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->definitions as $name => $definition) {
            $tools[] = $this->tool($name, $definition->description, $definition->parameters);
        }
        if (count($this->catalog) > count($this->definitions)) {
            $tools[] = $this->tool(self::FIND_TOOLS, self::findToolsDescription(), self::findToolsParameters());
        }

        return $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $name = $toolCall->getName();

        if ($this->state->isPaused()) {
            return new ToolResult($toolCall, 'Not executed: the editor has to review the previous step first.');
        }
        if ($name === self::FIND_TOOLS) {
            return new ToolResult($toolCall, $this->findTools((string) ($toolCall->getArguments()['query'] ?? '')));
        }
        if (!isset($this->catalog[$name])) {
            return new ToolResult($toolCall, sprintf('Tool "%s" is not available to this editor.', $name));
        }
        // A permitted tool the model knows from earlier turns: offer it from now on.
        $definition = $this->definitions[$name] ?? $this->addDefinitions([$this->catalog[$name]])[0] ?? null;
        if ($definition === null) {
            return new ToolResult($toolCall, sprintf('Tool "%s" is not available to this editor.', $name));
        }

        $validation = $this->runtime->argumentValidator->validate($definition->parameters, $toolCall->getArguments());
        if ($validation['errors'] !== []) {
            $this->state->trace[] = ['tool' => $name, 'invalidArguments' => $validation['errors']];

            return new ToolResult($toolCall, sprintf(
                'Not executed: invalid arguments for %s. %s Correct the arguments and call the tool again.',
                $name,
                implode(' ', $validation['errors']),
            ));
        }

        if ($name === self::ASK_CLARIFICATION) {
            return new ToolResult($toolCall, $this->askClarification($validation['arguments']));
        }

        $budgetMessage = $this->consumeBudget($name);
        if ($budgetMessage !== null) {
            $this->state->addMessage($budgetMessage);
            $this->state->pause((string) $budgetMessage['meta']['budgetKind'] . '_budget');

            return new ToolResult($toolCall, 'Not executed: the tool budget of this turn is used up.');
        }

        $this->state->emit('progress', ['status' => 'tool', 'tool' => $name, 'label' => $this->editorLabel($name)]);

        $body = $this->runtime->body;
        $body['arguments'] = $validation['arguments'];
        $message = $this->runtime->executor->execute($name, $this->runtime->context, $body, $this->runtime->user, $this->state->correlationId);

        if (isset($message['meta']['trace']) && is_array($message['meta']['trace'])) {
            $this->state->trace = array_merge($this->state->trace, array_values($message['meta']['trace']));
        }
        $this->state->executedTools[] = $name;
        // Cards from a natural-language turn continue the turn after the editor confirms / declines.
        $message['meta']['fromRunner'] = true;
        $this->state->addMessage($message);

        $pauseReason = $this->runtime->pausePolicy->pauseReason($message);
        if ($pauseReason !== null) {
            $this->state->pause($pauseReason);
        }

        return new ToolResult($toolCall, $this->summaryForModel($message));
    }

    public static function findToolsDescription(): string
    {
        return 'Search all TYPO3 backend tools this editor may use, by what should be done (any language).'
            . ' Use it when none of the offered tools fits the request. The found tools can be called in the next step.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function findToolsParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What the editor wants to do, in a few words, e.g. "translate news record" or "Alt-Texte für Bilder".',
                ],
            ],
            'required' => ['query'],
        ];
    }

    private function findTools(string $query): string
    {
        if (++$this->searches > self::MAX_TOOL_SEARCHES) {
            return 'Not executed: the tool search was used too often in this turn. Answer with the tools you have.';
        }
        $this->state->emit('progress', [
            'status' => 'tool',
            'tool' => self::FIND_TOOLS,
            'label' => $this->runtime->translator->translate('agent.live.findingTools'),
        ]);

        $candidates = array_values(array_diff_key($this->catalog, $this->definitions));
        $result = $this->runtime->toolSearch->search($query, $candidates, self::FOUND_TOOLS_LIMIT);
        $found = $this->addDefinitions($result['tools']);
        $this->state->trace[] = [
            'tool' => self::FIND_TOOLS,
            'query' => $query,
            'method' => $result['method'],
            'found' => array_map(static fn(AiToolDefinition $definition): string => $definition->name, $found),
        ];
        $this->state->foundTools = [...$this->state->foundTools, ...array_map(
            static fn(AiToolDefinition $definition): string => $definition->name,
            $found,
        )];

        if ($found === []) {
            return 'No matching tool. Tell the editor in one sentence that this is not possible with the AI Agent here.';
        }

        $lines = ['These tools are now available:'];
        foreach ($found as $definition) {
            $lines[] = sprintf(
                '- %s (%s): %s Parameters: %s',
                $definition->name,
                $this->severityOf($definition->name),
                trim($definition->description),
                $this->describeParameters($definition->parameters),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Shows the question with its options as buttons and ends the turn; the editor's answer
     * (typed or clicked) is the next message.
     *
     * @param array<string, mixed> $arguments
     */
    private function askClarification(array $arguments): string
    {
        $question = trim((string) ($arguments['question'] ?? ''));
        $options = AskClarificationTool::normalizeOptions(is_array($arguments['options'] ?? null) ? $arguments['options'] : []);
        $this->state->addMessage([
            'role' => 'assistant',
            'content' => $question,
            'meta' => [
                'type' => 'clarification',
                'tool' => self::ASK_CLARIFICATION,
                'options' => $options,
                'correlationId' => $this->state->correlationId,
                'orchestratorPause' => true,
                'llmSummary' => 'Asked the editor: ' . $question . ($options !== [] ? ' Options: ' . implode(' | ', $options) : ''),
            ],
        ]);
        $this->state->executedTools[] = self::ASK_CLARIFICATION;
        $this->state->pause('clarification');

        return 'The question is shown to the editor. Stop here and wait for the answer.';
    }

    private function editorLabel(string $name): string
    {
        $label = trim((string) ($this->catalog[$name]['editorLabel'] ?? ''));

        return $label !== '' ? $label : $name;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<AiToolDefinition>
     */
    private function addDefinitions(array $entries): array
    {
        $added = [];
        foreach ($this->runtime->definitionMapper->mapExecutableTools($entries) as $definition) {
            if (isset($this->catalog[$definition->name])) {
                $this->definitions[$definition->name] = $definition;
                $added[] = $definition;
            }
        }

        return $added;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function describeParameters(array $parameters): string
    {
        $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
        if ($properties === []) {
            return 'none.';
        }
        $required = is_array($parameters['required'] ?? null) ? $parameters['required'] : [];
        $parts = [];
        foreach ($properties as $name => $definition) {
            $type = is_array($definition) ? (string) ($definition['type'] ?? 'string') : 'string';
            $parts[] = $name . ' (' . $type . (in_array($name, $required, true) ? ', required' : '') . ')';
        }

        return implode(', ', $parts) . '.';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function tool(string $name, string $description, array $parameters): Tool
    {
        // The MCP JSON schema travels in the metadata; GovernedPlatform passes it to the provider as-is.
        return new Tool(
            new ExecutionReference(self::class, 'execute'),
            $name,
            $description,
            null,
            ['severity' => $name === self::FIND_TOOLS ? ToolSeverity::Read->value : $this->severityOf($name), 'parameters' => $parameters],
        );
    }

    private function severityOf(string $name): string
    {
        return $this->severities[$name] ?? ToolSeverity::Write->value;
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}|null
     */
    private function consumeBudget(string $name): ?array
    {
        if ($this->severityOf($name) === ToolSeverity::Read->value) {
            if ($this->state->readCount >= $this->runtime->maxReads) {
                return $this->budgetMessage('read', $this->runtime->maxReads);
            }
            ++$this->state->readCount;

            return null;
        }

        if ($this->state->writeCount >= $this->runtime->maxWrites) {
            return $this->budgetMessage('write', $this->runtime->maxWrites);
        }
        ++$this->state->writeCount;

        return null;
    }

    /**
     * @return array{role: string, content: string, meta: array<string, mixed>}
     */
    private function budgetMessage(string $kind, int $limit): array
    {
        return [
            'role' => 'assistant',
            'content' => $this->runtime->translator->translate(
                $kind === 'read' ? 'agent.turn.readBudgetExceeded' : 'agent.turn.writeBudgetExceeded',
                [(string) $limit],
            ),
            'meta' => [
                'type' => 'budget_exceeded',
                'correlationId' => $this->state->correlationId,
                'orchestratorPause' => true,
                'budgetKind' => $kind,
            ],
        ];
    }

    /**
     * @param array{role: string, content: string, meta: array<string, mixed>} $message
     */
    private function summaryForModel(array $message): string
    {
        foreach (['llmSummary', 'summary'] as $key) {
            $value = $message['meta'][$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        $content = trim($message['content']);

        return $content !== '' ? $content : 'Done.';
    }
}
