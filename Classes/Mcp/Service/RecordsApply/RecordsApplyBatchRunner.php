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

use const JSON_THROW_ON_ERROR;

use Mcp\Exception\ToolCallException;

/**
 * The records_apply engine for tools that already know their records and only want them written atomically.
 *
 * Used by the generated `<prefix>_delete_batch`, `_update_batch` and `_move_batch` tools: their records are
 * already checked to exist, so the engine is run non-strict (fields the user may not write are dropped, as
 * DataHandler always did), without append ordering, and a refusal by the engine's checks becomes a tool error.
 */
readonly class RecordsApplyBatchRunner
{
    public function __construct(private RecordsApplyService $service) {}

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, array<int, array<string, int>>> $cmdmap
     * @param string $tool name of the calling tool, for the audit entry
     * @throws ToolCallException when anything is refused or fails; nothing was written
     */
    public function run(string $tool, array $datamap, array $cmdmap): RecordsApplyResult
    {
        try {
            return $this->service->apply($datamap, $cmdmap, false, false, false, [], $tool);
        } catch (RecordsApplyValidationException $exception) {
            $payload = [
                'ok' => false,
                'written' => false,
                'stage' => 'validation',
                'note' => 'The request was refused before anything was written. Fix the problems and send it again.',
                'problems' => $exception->getProblems(),
            ];
            if ($exception->getMoreProblems() > 0) {
                $payload['moreProblems'] = $exception->getMoreProblems();
            }

            throw new ToolCallException(json_encode($payload, JSON_THROW_ON_ERROR), 1790500006, $exception);
        }
    }
}
