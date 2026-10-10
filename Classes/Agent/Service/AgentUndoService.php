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

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;

/**
 * Reverts a single applied agent change via DataHandler (T14).
 *
 * @internal
 */
final class AgentUndoService
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly AgentDraftSession $draftSession,
        private readonly AgentTranslator $translator,
        private readonly RecordService $recordService,
    ) {}

    /**
     * Workspace the change was applied in (0 = Live); null for changes stored before it was recorded.
     */
    public function workspaceOf(string $changeId): ?int
    {
        $stored = $this->draftSession->getChange($changeId);
        if ($stored === null || !array_key_exists('workspaceId', $stored)) {
            return null;
        }

        return (int) $stored['workspaceId'];
    }

    /**
     * Whether undo can bring the change back. A delete cannot be undone from here (the editor has
     * to restore it from the recycle bin), so the card must not offer an Undo button for it.
     *
     * @param array<int|string, mixed> $undoFields
     */
    public static function isUndoable(array $undoFields): bool
    {
        $revertible = false;
        foreach ($undoFields as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $action = (string) ($entry['action'] ?? '');
            if ($action === 'delete') {
                return false;
            }
            $field = (string) ($entry['field'] ?? '');
            if (($action === 'create' || $action === 'copy' || !str_starts_with($field, '_'))
                && (string) ($entry['table'] ?? '') !== ''
                && (int) ($entry['uid'] ?? 0) > 0
            ) {
                $revertible = true;
            }
        }

        return $revertible;
    }

    /**
     * Refuse to put an old value back over something somebody else saved after the agent's change: the
     * value the agent wrote must still be what is stored now.
     *
     * @param array<int|string, mixed> $undoFields
     */
    private function assertNotChangedSince(array $undoFields): void
    {
        foreach ($undoFields as $entry) {
            if (!is_array($entry) || !array_key_exists('appliedValue', $entry) || ($entry['action'] ?? '') !== 'update') {
                continue;
            }
            $table = (string) ($entry['table'] ?? '');
            $uid = (int) ($entry['uid'] ?? 0);
            $field = (string) ($entry['field'] ?? '');
            if ($table === '' || $uid <= 0 || $field === '' || str_starts_with($field, '_')) {
                continue;
            }

            $record = $this->recordService->findByUid($table, $uid, [$field]);
            if ($record === null || !array_key_exists($field, $record)) {
                continue;
            }
            if (self::comparable($record[$field]) !== self::comparable($entry['appliedValue'])) {
                throw new \RuntimeException(
                    $this->translator->translate('agent.undo.changedSince', [$field, self::comparable($record[$field])]),
                    1712003303,
                );
            }
        }
    }

    private static function comparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : (string) json_encode($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function undo(string $changeId): array
    {
        $stored = $this->draftSession->getChange($changeId);
        if ($stored === null) {
            throw new \RuntimeException($this->translator->translate('agent.undo.changeNotFound'), 1712003300);
        }

        $undoFields = is_array($stored['undoFields'] ?? null) ? $stored['undoFields'] : [];
        $this->assertNotChangedSince($undoFields);
        $reverted = [];
        // One DataHandler write per record: empty SEO restores stick when batched (field-at-a-time often no-ops).
        /** @var array<string, array{table: string, uid: int, fields: array<string, mixed>}> $updatesByRecord */
        $updatesByRecord = [];

        foreach ($undoFields as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $table = (string) ($entry['table'] ?? '');
            $uid = (int) ($entry['uid'] ?? 0);
            $field = (string) ($entry['field'] ?? '');
            $action = (string) ($entry['action'] ?? '');
            $previousValue = $entry['previousValue'] ?? null;

            if ($table === '' || $uid <= 0) {
                continue;
            }

            $parent = is_array($entry['parent'] ?? null) ? $entry['parent'] : [];
            if ($action === 'create' && $table === 'sys_file_reference' && $parent !== []) {
                // Only the reference goes; the file and the record it was attached to stay.
                $removed = $this->dataHandlerService->removeFileReferences(
                    (string) ($parent['table'] ?? ''),
                    (int) ($parent['uid'] ?? 0),
                    (string) ($parent['field'] ?? ''),
                    [$uid],
                );
                if ($removed !== []) {
                    $reverted[] = ['table' => $table, 'uid' => $uid, 'field' => $field, 'reverted' => 'deleted'];
                }
                continue;
            }

            if ($action === 'create' || $action === 'copy') {
                $this->dataHandlerService->deleteRecord($table, $uid);
                $reverted[] = ['table' => $table, 'uid' => $uid, 'field' => $field, 'reverted' => 'deleted'];
                continue;
            }

            if ($action === 'delete') {
                // ponytail: undelete not supported via DataHandler cmdmap; ceiling is manual restore from recycle bin.
                throw new \RuntimeException($this->translator->translate('agent.undo.deleteUnsupported'), 1712003301);
            }

            if ($field === '_record' || str_starts_with($field, '_')) {
                continue;
            }

            $key = $table . '#' . $uid;
            $updatesByRecord[$key]['table'] = $table;
            $updatesByRecord[$key]['uid'] = $uid;
            $updatesByRecord[$key]['fields'][$field] = $previousValue;
        }

        foreach ($updatesByRecord as $batch) {
            $table = $batch['table'];
            $uid = $batch['uid'];
            $fields = $batch['fields'];
            if ($fields === []) {
                continue;
            }
            $this->dataHandlerService->updateRecord($table, $uid, $fields);
            $after = $this->recordService->findByUid($table, $uid, array_keys($fields));
            foreach ($fields as $field => $previousValue) {
                if ($after === null || !array_key_exists($field, $after)
                    || self::comparable($after[$field]) !== self::comparable($previousValue)
                ) {
                    continue;
                }
                $reverted[] = [
                    'table' => $table,
                    'uid' => $uid,
                    'field' => $field,
                    'reverted' => 'restored',
                    'previousValue' => is_scalar($previousValue) ? (string) $previousValue : '',
                ];
            }
        }

        if ($reverted === []) {
            // Never report a successful undo when nothing was reverted.
            throw new \RuntimeException($this->translator->translate('agent.undo.nothingToRevert'), 1712003302);
        }

        $this->draftSession->removeChange($changeId);

        return [
            'changeId' => $changeId,
            'correlationId' => (string) ($stored['correlationId'] ?? ''),
            'reverted' => $reverted,
        ];
    }
}
