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
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileSource;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;

/**
 * Batch writes: many records, across tables, in one atomic call.
 *
 * Hidden from the AI Agent until its approval flow handles batches (the Agent drafts and asks the
 * editor per change); external MCP clients get it from the start.
 */
#[McpToolSeverity(ToolSeverity::Destructive)]
#[McpAgentHidden]
readonly class RecordsApplyTool implements McpNonAiToolInterface
{
    /** What the three JSON arguments may weigh together. Bigger batches are split by the client. */
    public const MAX_PAYLOAD_BYTES = 2097152;

    public function __construct(
        private RecordsApplyService $service,
        private RecordsApplyFileSource $fileSource,
    ) {}

    #[McpTool(
        name: 'records_apply',
        description: 'Create, update, delete and move MANY records across tables in ONE atomic call, through TYPO3 DataHandler.'
            . ' data = {"<table>": {"<uid or NEWid>": {<fields>}}}. A new record uses an id of NEW plus letters/digits (NEWpage1, no underscore)'
            . ' and needs "pid"; other records in the same call may point at it: pid "NEWpage1" puts a record on that new page,'
            . ' and file, inline and category/MM fields take "NEWa,NEWb" or [uid, "NEWb"] lists.'
            . ' cmd = {"<table>": {"<uid>": {"delete": 1 | "move": <pid, or -uid to go after a record> | "copy": <target> | "undelete": 1 | "localize": <language uid>}}}.'
            . ' bulk = [{"table": "tt_content", "uids": [1, 2, 3], "set": {"hidden": 1}}] is a shorthand for the same field change on many records;'
            . ' use "delete": true or "move": <target> instead of "set" for those. Each record may be named once per section.'
            . ' New records are appended after the existing ones on their page in the order sent (append=true); give a negative pid to place one yourself.'
            . ' strict=true (default) refuses the WHOLE call if any field is not writable and names it; strict=false drops those fields and reports them.'
            . ' dryRun=true runs everything for real, reports the result, then rolls everything back: use it first for deletes and large batches.'
            . ' All-or-nothing: any refusal or error rolls back every change. Limit: 500 records per call (data + cmd), split larger batches.'
            . ' requestId (e.g. a UUID) makes a retry safe: resend the SAME request with the SAME requestId after a timeout and it is applied once;'
            . ' you get the first answer back with replayed=true. A requestId used with a different request is refused. Dry runs ignore it.'
            . ' Inline, data + cmd + bulk may weigh 2 MB together.'
            . ' fromFile = the sys_file uid of a .json file you uploaded, holding {"data": {...}, "cmd": {...}, "bulk": [...]} (each optional), for batches too big to send inline'
            . ' (up to 10 MB). Then leave data, cmd and bulk empty. The file is checked exactly like inline arguments and you need read access to it.'
            . ' Prefer fromPayloadId after file_upload_prepare with purpose=records_apply_payload: private one-time storage, not web-readable.'
            . ' fromPayloadId = the payloadId returned from that upload; same JSON shape as fromFile.'
            . ' Your own backend permissions apply. Returns "map" (NEW id => uid) and a batchId.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
        ),
    )]
    public function execute(
        string $data = '{}',
        string $cmd = '{}',
        bool $dryRun = false,
        bool $strict = true,
        bool $append = true,
        string $bulk = '[]',
        string $requestId = '',
        int $fromFile = 0,
        string $fromPayloadId = '',
    ): string {
        $fromPayloadId = trim($fromPayloadId);
        if ($fromFile > 0 && $fromPayloadId !== '') {
            throw new ToolCallException('Use either fromFile or fromPayloadId, not both. Nothing was written.', 1790500043);
        }

        if ($fromFile > 0 || $fromPayloadId !== '') {
            if (!in_array(trim($data), ['', '{}'], true) || !in_array(trim($cmd), ['', '{}'], true) || !in_array(trim($bulk), ['', '[]'], true)) {
                throw new ToolCallException('With fromFile or fromPayloadId, data, cmd and bulk must be empty. Nothing was written.', 1790500026);
            }

            [$datamap, $cmdmap, $bulkEntries] = $fromPayloadId !== ''
                ? $this->fileSource->readFromPayloadId($fromPayloadId)
                : $this->fileSource->read($fromFile);
        } else {
            $size = strlen($data) + strlen($cmd) + strlen($bulk);
            if ($size > self::MAX_PAYLOAD_BYTES) {
                throw new ToolCallException(
                    sprintf(
                        'The request is too large: %d bytes, the maximum is %d. Nothing was written. Split it into several calls, or upload it as a .json file and pass fromFile.',
                        $size,
                        self::MAX_PAYLOAD_BYTES,
                    ),
                    1790500009,
                );
            }

            $datamap = $this->decodeObject($data, 'data');
            $cmdmap = $this->decodeObject($cmd, 'cmd');
            $bulkEntries = $this->decodeList($bulk, 'bulk');
        }

        try {
            $result = $this->service->apply($datamap, $cmdmap, $dryRun, $strict, $append, $bulkEntries, 'records_apply', trim($requestId));
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

            throw new ToolCallException(json_encode($payload, JSON_THROW_ON_ERROR), $exception->getToolResponseCode(), $exception);
        }

        $response = $result->toArray();
        if (($response['map'] ?? null) === []) {
            // "map" is NEW id => uid. An empty one is an empty object, not an empty list.
            $response['map'] = new \stdClass();
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<mixed>
     */
    private function decodeList(string $json, string $name): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ToolCallException(
                sprintf('%s must be a JSON list (%s).', $name, $exception->getMessage()),
                1790500010,
                $exception,
            );
        }

        if (!is_array($decoded) || ($decoded !== [] && !array_is_list($decoded))) {
            throw new ToolCallException(sprintf('%s must be a JSON list, e.g. [{"table": "tt_content", "uids": [1, 2], "set": {"hidden": 1}}].', $name), 1790500011);
        }

        return $decoded;
    }

    /**
     * @return array<mixed>
     */
    private function decodeObject(string $json, string $name): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ToolCallException(
                sprintf('%s must be a JSON object (%s).', $name, $exception->getMessage()),
                1790500007,
                $exception,
            );
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ToolCallException(sprintf('%s must be a JSON object, e.g. {"tt_content": {"NEWc1": {"pid": 12, "header": "Hi"}}}.', $name), 1790500008);
        }

        return $decoded;
    }
}
