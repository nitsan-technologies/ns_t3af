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
     */
    public function __construct(
        public string $batchId,
        public bool $dryRun,
        public bool $written,
        public array $created,
        public array $copied,
        public array $operations,
        public array $ignoredFields,
    ) {}

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

        if ($this->copied !== []) {
            $result['copied'] = $this->copied;
        }

        if ($this->ignoredFields !== []) {
            $result['ignoredFields'] = $this->ignoredFields;
        }

        return $result;
    }
}
