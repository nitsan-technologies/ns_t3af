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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function undo(string $changeId): array
    {
        $stored = $this->draftSession->getChange($changeId);
        if ($stored === null) {
            throw new \RuntimeException('Change not found or already undone.', 1712003300);
        }

        $undoFields = is_array($stored['undoFields'] ?? null) ? $stored['undoFields'] : [];
        $reverted = [];

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

            if ($action === 'create' || $action === 'copy') {
                $this->dataHandlerService->deleteRecord($table, $uid);
                $reverted[] = ['table' => $table, 'uid' => $uid, 'field' => $field, 'reverted' => 'deleted'];
                continue;
            }

            if ($action === 'delete') {
                // ponytail: undelete not supported via DataHandler cmdmap; ceiling is manual restore from recycle bin.
                throw new \RuntimeException('Undo of delete is not supported.', 1712003301);
            }

            if ($field === '_record' || str_starts_with($field, '_')) {
                continue;
            }

            $this->dataHandlerService->updateRecord($table, $uid, [$field => $previousValue]);
            $reverted[] = ['table' => $table, 'uid' => $uid, 'field' => $field, 'reverted' => 'restored'];
        }

        $this->draftSession->removeChange($changeId);

        return [
            'changeId' => $changeId,
            'correlationId' => (string) ($stored['correlationId'] ?? ''),
            'reverted' => $reverted,
        ];
    }
}
