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

namespace NITSAN\NsT3AF\Mcp\Tool\Record;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use NITSAN\NsT3AF\Mcp\Attribute\McpAgentHidden;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsUndoService;

/**
 * Takes back a whole records_apply batch: creates are deleted, deletes are undeleted, field changes and moves are reverted.
 *
 * Hidden from the AI Agent for the same reason as records_apply (see there).
 */
#[McpToolSeverity(ToolSeverity::Destructive)]
#[McpAgentHidden]
readonly class RecordsUndoTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordsUndoService $service,
    ) {}

    #[McpTool(
        name: 'records_undo',
        description: 'Take back a whole records_apply batch by its batchId: records it created are deleted, records it deleted are restored,'
            . ' field changes and moves are reverted. One atomic call, through DataHandler, with your own backend permissions.'
            . ' Refuses (and writes nothing) when anybody changed one of those records after the batch, when the batch was already undone,'
            . ' when it ran in a workspace (use workspace_discard) or when pages it created now hold records it did not create.'
            . ' Relation fields that appear in the history diff are listed under notRestored and are not restored;'
            . ' records the batch created (for example file references) are removed by the undo.'
            . ' Records changed and deleted in the same batch come back with their changes not reverted.'
            . ' dryRun=true runs it for real and rolls back: use it first. The undo is a batch itself (its batchId is returned) and can be undone in turn.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
        ),
    )]
    public function execute(string $batchId, bool $dryRun = false): string
    {
        try {
            $outcome = $this->service->undo(trim($batchId), $dryRun);
        } catch (RecordsApplyValidationException $exception) {
            $payload = [
                'ok' => false,
                'written' => false,
                'stage' => 'validation',
                'note' => 'The undo was refused before anything was written. The records may have changed or be out of your reach since the batch.',
                'problems' => $exception->getProblems(),
            ];
            if ($exception->getMoreProblems() > 0) {
                $payload['moreProblems'] = $exception->getMoreProblems();
            }

            throw new ToolCallException(json_encode($payload, JSON_THROW_ON_ERROR), 1790500006, $exception);
        }

        $response = $outcome['result']->toArray();
        $response['undoes'] = $outcome['undoes'];
        if ($outcome['notRestored'] !== []) {
            $response['notRestored'] = $outcome['notRestored'];
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }
}
