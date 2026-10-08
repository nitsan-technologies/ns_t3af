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

namespace NITSAN\NsT3AF\Mcp\Service\RecordsApply;

/**
 * Outcome of one records_apply call.
 *
 * `written` is false for a dry run, which runs the real DataHandler and then rolls everything back:
 * `created` then lists the uids the records WOULD have got.
 */
final readonly class RecordsApplyResult
{
    /**
     * @param array<string, int> $created NEW id => uid
     * @param array<string, array<int, int>> $copied table => [source uid => copy uid]
     * @param array<string, array<string, int>> $operations table => [operation => number of records]
     * @param list<array{table: string, id: string, fields: list<string>}> $ignoredFields non-strict mode only
     * @param int $aiLabelled how many records were marked as AI-involved in the AI Label module
     * @param bool $replayed true when this is the stored answer of an earlier call with the same requestId; nothing was written now
     * @param array{id: int, title: string}|null $workspace the workspace the call ran in (0 = Live), so the client can see where the change went
     */
    public function __construct(
        public string $batchId,
        public bool $dryRun,
        public bool $written,
        public array $created,
        public array $copied,
        public array $operations,
        public array $ignoredFields,
        public int $aiLabelled = 0,
        public bool $replayed = false,
        public ?array $workspace = null,
    ) {}

    public function withAiLabelled(int $aiLabelled): self
    {
        return new self(
            $this->batchId,
            $this->dryRun,
            $this->written,
            $this->created,
            $this->copied,
            $this->operations,
            $this->ignoredFields,
            $aiLabelled,
            $this->replayed,
            $this->workspace,
        );
    }

    public function withWorkspace(int $id, string $title): self
    {
        return new self(
            $this->batchId,
            $this->dryRun,
            $this->written,
            $this->created,
            $this->copied,
            $this->operations,
            $this->ignoredFields,
            $this->aiLabelled,
            $this->replayed,
            ['id' => $id, 'title' => $title],
        );
    }

    public function withReplayed(): self
    {
        return new self(
            $this->batchId,
            $this->dryRun,
            $this->written,
            $this->created,
            $this->copied,
            $this->operations,
            $this->ignoredFields,
            0,
            true,
            $this->workspace,
        );
    }

    /**
     * Rebuilds a result from what toArray() produced, for a requestId replay.
     *
     * @param array<mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $created = [];
        foreach (is_array($stored['map'] ?? null) ? $stored['map'] : [] as $newId => $uid) {
            if (is_numeric($uid)) {
                $created[(string) $newId] = (int) $uid;
            }
        }

        $copied = [];
        foreach (is_array($stored['copied'] ?? null) ? $stored['copied'] : [] as $table => $mapping) {
            foreach (is_array($mapping) ? $mapping : [] as $sourceUid => $copyUid) {
                if (is_numeric($copyUid)) {
                    $copied[(string) $table][(int) $sourceUid] = (int) $copyUid;
                }
            }
        }

        $operations = [];
        foreach (is_array($stored['operations'] ?? null) ? $stored['operations'] : [] as $table => $counts) {
            foreach (is_array($counts) ? $counts : [] as $operation => $count) {
                if (is_numeric($count)) {
                    $operations[(string) $table][(string) $operation] = (int) $count;
                }
            }
        }

        $ignored = [];
        foreach (is_array($stored['ignoredFields'] ?? null) ? $stored['ignoredFields'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['table'] ?? null) || !is_array($entry['fields'] ?? null)) {
                continue;
            }

            $ignored[] = [
                'table' => $entry['table'],
                'id' => (string) ($entry['id'] ?? ''),
                'fields' => array_values(array_map('strval', $entry['fields'])),
            ];
        }

        $workspace = null;
        if (is_array($stored['workspace'] ?? null) && is_numeric($stored['workspace']['id'] ?? null)) {
            $workspace = ['id' => (int) $stored['workspace']['id'], 'title' => (string) ($stored['workspace']['title'] ?? '')];
        }

        return new self(
            is_string($stored['batchId'] ?? null) ? $stored['batchId'] : '',
            false,
            true,
            $created,
            $copied,
            $operations,
            $ignored,
            0,
            false,
            $workspace,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'ok' => true,
            'dryRun' => $this->dryRun,
            'written' => $this->written,
            'batchId' => $this->batchId,
            'operations' => $this->operations,
            'map' => $this->created,
        ];

        if ($this->workspace !== null) {
            $result['workspace'] = $this->workspace;
        }

        if ($this->copied !== []) {
            $result['copied'] = $this->copied;
        }

        if ($this->ignoredFields !== []) {
            $result['ignoredFields'] = $this->ignoredFields;
        }

        if ($this->aiLabelled > 0) {
            $result['aiLabelled'] = $this->aiLabelled;
        }

        if ($this->replayed) {
            $result['replayed'] = true;
            $result['note'] = 'This requestId was already applied. This is the stored answer of that call, nothing was written now.';
        }

        return $result;
    }
}
