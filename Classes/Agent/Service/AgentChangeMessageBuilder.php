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
 * The one plain sentence shown after an Apply or an Undo ("Done: Page „About“ was updated. Header is
 * now “New title”."), built from what really happened instead of a field counter.
 *
 * @internal
 */
final readonly class AgentChangeMessageBuilder
{
    private const MAX_VALUE_LENGTH = 60;

    private const MAX_FIELDS_LISTED = 4;

    public function __construct(
        private AgentTranslator $translator,
        private AgentRecordLabeler $recordLabeler,
    ) {}

    /**
     * @param array<string, mixed> $result labelled apply result (readback entries carry recordLabel and fieldLabels)
     */
    public function applied(array $result): string
    {
        $applied = (int) ($result['appliedCount'] ?? 0);
        $total = (int) ($result['totalCount'] ?? 0);
        $fallback = $this->translator->translate('agent.draft.applied', [(string) $applied, (string) $total]);

        $action = (string) ($result['action'] ?? '');
        $readback = array_values(array_filter(
            is_array($result['readback'] ?? null) ? $result['readback'] : [],
            static fn(mixed $entry): bool => is_array($entry),
        ));
        if ($readback === [] || $applied < $total || !in_array($action, ['', 'update', 'create'], true)) {
            return $fallback;
        }

        if (count($readback) > 1) {
            return $this->translator->translate('agent.draft.doneRecords', [(string) count($readback)]);
        }

        $entry = $readback[0];
        $recordLabel = trim((string) ($entry['recordLabel'] ?? ''));
        // An update names the record as it was called before the change (a rename must not rename the record in the message).
        $labelBefore = trim((string) ($entry['recordLabelBefore'] ?? ''));
        if ($action !== 'create' && $labelBefore !== '') {
            $recordLabel = $labelBefore;
        }
        if ($recordLabel === '') {
            return $fallback;
        }
        if ($action === 'create') {
            $placement = trim((string) ($result['placement'] ?? ''));
            if ($placement !== '') {
                return $this->translator->translate('agent.draft.doneCreatedAt', [$recordLabel, $placement]);
            }

            return $this->translator->translate('agent.draft.doneCreated', [$recordLabel]);
        }

        $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
        $fieldLabels = is_array($entry['fieldLabels'] ?? null) ? $entry['fieldLabels'] : [];
        if ($values === []) {
            return $fallback;
        }
        if (count($values) === 1) {
            $field = (string) array_key_first($values);
            $value = $values[$field];
            if (is_scalar($value) || $value === null) {
                return $this->translator->translate('agent.draft.doneOneField', [
                    $recordLabel,
                    (string) ($fieldLabels[$field] ?? $field),
                    $this->shorten((string) $value),
                ]);
            }
        }

        $names = [];
        foreach (array_keys($values) as $field) {
            $names[] = (string) ($fieldLabels[(string) $field] ?? $field);
        }
        $list = implode(', ', array_slice($names, 0, self::MAX_FIELDS_LISTED));
        if (count($names) > self::MAX_FIELDS_LISTED) {
            $list .= ', …';
        }

        return $this->translator->translate('agent.draft.doneFields', [$recordLabel, $list]);
    }

    /**
     * @param array<string, mixed> $undoResult result of {@see AgentUndoService::undo()}
     */
    public function undone(array $undoResult): string
    {
        $reverted = array_values(array_filter(
            is_array($undoResult['reverted'] ?? null) ? $undoResult['reverted'] : [],
            static fn(mixed $entry): bool => is_array($entry),
        ));
        $restored = array_values(array_filter($reverted, static fn(array $entry): bool => ($entry['reverted'] ?? '') === 'restored'));
        $removed = array_values(array_filter($reverted, static fn(array $entry): bool => ($entry['reverted'] ?? '') === 'deleted'));

        if (count($restored) === 1 && $removed === []) {
            $entry = $restored[0];
            $label = $this->recordLabeler->fieldLabel((string) ($entry['table'] ?? ''), (string) ($entry['field'] ?? ''));
            $previous = $this->shorten((string) ($entry['previousValue'] ?? ''));

            return $previous === ''
                ? $this->translator->translate('agent.draft.undoneFieldEmpty', [$label])
                : $this->translator->translate('agent.draft.undoneField', [$label, $previous]);
        }
        if ($restored === [] && $removed !== []) {
            return $this->translator->translate('agent.draft.undoneCreated');
        }
        if ($reverted !== []) {
            return $this->translator->translate('agent.draft.undoneFields', [(string) count($reverted)]);
        }

        return $this->translator->translate('agent.draft.undone');
    }

    private function shorten(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return mb_strlen($value) > self::MAX_VALUE_LENGTH ? rtrim(mb_substr($value, 0, self::MAX_VALUE_LENGTH - 1)) . '…' : $value;
    }
}
