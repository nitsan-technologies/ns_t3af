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
 * Keeps the stored conversation up to date when the editor acts on a card.
 *
 * The server is the only writer of a conversation: turns are stored by the turn endpoints,
 * and apply / decline / arm / undo update the card (found by its draft id) and append the
 * result here. The agent window only shows what it gets back; it does not save messages.
 *
 * All methods are pure transformations of the message list, so they are easy to test; the
 * controller loads and saves the conversation around them.
 *
 * @internal
 */
final readonly class AgentConversationRecorder
{
    public function __construct(
        private AgentTranslator $translator,
        private AgentToolEditorLabelService $editorLabelService,
    ) {}

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $result result of AgentWriteService::apply / applySuggestions (labelled readback)
     * @param array{message?: string, links?: list<mixed>, schedulerHandoff?: mixed, keptFieldKeys?: list<string>|null, selections?: array<mixed>|null, edits?: array<mixed>|null} $response
     * @return list<array<string, mixed>>
     */
    public function applied(array $messages, string $draftId, array $result, array $response): array
    {
        $index = $this->findCard($messages, $draftId);
        if ($index === null) {
            return $messages;
        }
        $card = $messages[$index];
        $meta = is_array($card['meta'] ?? null) ? $card['meta'] : [];
        $links = array_values(is_array($response['links'] ?? null) ? $response['links'] : []);
        $handoff = $response['schedulerHandoff'] ?? null;
        $responseMessage = trim((string) ($response['message'] ?? ''));

        if (($meta['type'] ?? '') === 'suggestions') {
            $appliedCount = (int) ($result['appliedCount'] ?? 0);
            $meta['applied'] = true;
            $meta['appliedCount'] = $appliedCount;
            $meta['changeId'] = (string) ($result['changeId'] ?? '');
            if (is_array($response['selections'] ?? null)) {
                $meta['selections'] = $response['selections'];
            }
            if (is_array($response['edits'] ?? null) && $response['edits'] !== []) {
                $meta['edits'] = $response['edits'];
            }
            unset($meta['applying']);
            $messages[$index]['meta'] = $meta;
            $messages[] = $this->readbackMessage(
                $responseMessage !== '' ? $responseMessage : $this->translator->translate('agent.suggestions.applied', [(string) $appliedCount]),
                $result,
                (string) ($meta['correlationId'] ?? ''),
                $handoff,
                $links,
                ['appliedValues' => is_array($result['appliedValues'] ?? null) ? $result['appliedValues'] : []],
            );

            return $messages;
        }

        $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : [];

        if (($draft['kind'] ?? '') === SatelliteToolPlanService::PLAN_KIND_TOOL_CONFIRMATION) {
            // The confirmation card turns into the tool's result card. The live "Worked for …"
            // header is not stored (it is only shown right after the click).
            $presented = is_array($result['presentation'] ?? null) ? $result['presentation'] : [];
            $tool = (string) ($result['tool'] ?? $draft['tool'] ?? '');
            $content = trim((string) ($presented['content'] ?? ''));
            $messages[$index] = [
                'role' => 'assistant',
                'content' => $content !== '' ? $content : ($responseMessage !== '' ? $responseMessage : $this->translator->translate('agent.draft.toolApplied')),
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => $tool,
                    'toolCallLabel' => $this->label($draft, $tool),
                    'success' => ($presented['success'] ?? true) !== false,
                    'severity' => (string) ($draft['severity'] ?? 'write'),
                    'severityLabel' => 'Write',
                    'facts' => array_values(is_array($presented['facts'] ?? null) ? $presented['facts'] : []),
                    'details' => $presented['details'] ?? null,
                    'autoRan' => false,
                    'correlationId' => (string) ($result['correlationId'] ?? $meta['correlationId'] ?? ''),
                    'schedulerHandoff' => $handoff,
                    'links' => $links,
                    'fromRunner' => ($meta['fromRunner'] ?? false) === true,
                ],
            ];

            return $messages;
        }

        $kept = is_array($response['keptFieldKeys'] ?? null) ? $response['keptFieldKeys'] : null;
        if ($kept !== null && is_array($draft['fields'] ?? null)) {
            $draft['fields'] = array_map(
                static fn(mixed $field): mixed => is_array($field)
                    ? [...$field, 'kept' => in_array((string) ($field['key'] ?? ''), $kept, true)]
                    : $field,
                $draft['fields'],
            );
        }
        $draft['applied'] = true;
        unset($draft['applying'], $draft['applyStartedAt']);
        $meta['draft'] = $draft;
        $messages[$index]['meta'] = $meta;
        $messages[] = $this->readbackMessage(
            $responseMessage !== ''
                ? $responseMessage
                : $this->translator->translate('agent.draft.applied', [(string) ($result['appliedCount'] ?? 0), (string) ($result['totalCount'] ?? 0)]),
            $result,
            (string) ($meta['correlationId'] ?? ''),
            $handoff,
            $links,
        );

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function declined(array $messages, string $draftId): array
    {
        $index = $this->findCard($messages, $draftId);
        if ($index === null) {
            return $messages;
        }
        $meta = is_array($messages[$index]['meta'] ?? null) ? $messages[$index]['meta'] : [];
        if (($meta['type'] ?? '') === 'suggestions') {
            $meta['discarded'] = true;
        } else {
            $draft = is_array($meta['draft'] ?? null) ? $meta['draft'] : [];
            $draft['discarded'] = true;
            $meta['draft'] = $draft;
        }
        $messages[$index]['meta'] = $meta;
        $messages[$index]['content'] = $this->translator->translate('agent.draft.discarded');

        return $messages;
    }

    /**
     * First click on a destructive card.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function armed(array $messages, string $draftId): array
    {
        $index = $this->findCard($messages, $draftId);
        if ($index === null || !is_array($messages[$index]['meta']['draft'] ?? null)) {
            return $messages;
        }
        $messages[$index]['meta']['draft']['destructiveArmed'] = true;

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function undone(array $messages, string $message): array
    {
        $messages[] = [
            'role' => 'assistant',
            'content' => $message !== '' ? $message : $this->translator->translate('agent.draft.undone'),
            'meta' => ['type' => 'info'],
        ];

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function findCard(array $messages, string $draftId): ?int
    {
        if ($draftId === '') {
            return null;
        }
        foreach ($messages as $index => $message) {
            $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
            $type = (string) ($meta['type'] ?? '');
            if ($type === 'inline_draft' && (string) ($meta['draft']['draftId'] ?? '') === $draftId) {
                return $index;
            }
            if ($type === 'suggestions' && (string) ($meta['draftId'] ?? '') === $draftId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function label(array $draft, string $tool): string
    {
        foreach (['editorLabel', 'toolCallLabel', 'label'] as $key) {
            $label = trim((string) ($draft[$key] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return $tool !== '' ? $this->editorLabelService->resolveByName($tool) : '';
    }

    /**
     * @param array<string, mixed> $result
     * @param list<mixed> $links
     * @param array<string, mixed> $extraMeta
     * @return array<string, mixed>
     */
    private function readbackMessage(string $content, array $result, string $cardCorrelationId, mixed $handoff, array $links, array $extraMeta = []): array
    {
        return [
            'role' => 'assistant',
            'content' => $content,
            'meta' => [
                'type' => 'readback_result',
                'readback' => array_values(is_array($result['readback'] ?? null) ? $result['readback'] : []),
                'changeId' => (string) ($result['changeId'] ?? ''),
                'correlationId' => (string) ($result['correlationId'] ?? $cardCorrelationId),
                'schedulerHandoff' => $handoff,
                'links' => $links,
                ...$extraMeta,
            ],
        ];
    }
}
