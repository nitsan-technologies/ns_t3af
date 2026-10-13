<?php

/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use NITSAN\NsT3AF\Access\RecordAccessGate;
use NITSAN\NsT3AF\Agent\Service\PageCreateAfter;
use NITSAN\NsT3AF\Agent\Service\PageCreateBefore;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Contract\McpPlannableToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\RecordPayloadNormalizer;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileAccess;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyResult;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlan;
use NITSAN\NsT3AF\Mcp\Tool\Result\ToolPlanField;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * One record per call. The write itself runs on the records_apply engine (preflight, one DataHandler
 * run in a transaction, audit, AI Label marking), so a single write and a batch behave the same way.
 * Not strict by default: fields that cannot be written are dropped and reported in "ignoredFields", as before.
 * With strict=true the call is refused instead, and names those fields.
 * A positive pid creates the record as the first child of that page. A negative pid places it directly after that record.
 * File fields are attached afterwards, outside that transaction.
 */
#[McpToolSeverity(ToolSeverity::Write)]
readonly class WriteTableTool implements McpNonAiToolInterface, McpPlannableToolInterface
{
    /** Fields every create carries; they do not count as content the editor asked for. */
    private const STRUCTURAL_FIELDS = ['CType', 'colPos', 'sys_language_uid', 'l18n_parent', 'hidden', 'sorting', 'list_type'];

    /** A 0 on these fields is what a new record has anyway; a card row "Publish Date 0" only confuses the editor. */
    private const ZERO_IS_DEFAULT_FIELDS = ['list_type', 'l18n_parent', 'l10n_parent', 'starttime', 'endtime', 'fe_group', 'sorting', 'editlock'];

    /** Text fields where a rewrite must stay about the same thing. */
    private const REWRITE_CHECKED_FIELDS = ['bodytext', 'header', 'subheader', 'teaser'];

    /** A NEW id for the one record of a create. */
    private const NEW_ID = 'NEWrecord';

    private RecordPayloadNormalizer $normalizer;

    public function __construct(
        private DataHandlerService $dataHandlerService,
        private RecordService $recordService,
        TcaSchemaService $tcaSchemaService,
        private RecordsApplyService $recordsApply,
        private ?PageAccessService $pageAccess = null,
        private ?RecordsApplyFileAccess $fileAccess = null,
    ) {
        $this->normalizer = new RecordPayloadNormalizer($tcaSchemaService);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function plan(array $arguments): ToolPlan
    {
        $tableName = (string) ($arguments['tableName'] ?? ($arguments['table'] ?? ''));
        $uid = (int) ($arguments['uid'] ?? 0);
        $dataRaw = $arguments['data'] ?? '{}';
        $dataRaw = is_string($dataRaw) ? $dataRaw : json_encode($dataRaw, JSON_THROW_ON_ERROR);
        $payloadHint = self::decodeDataLoose($dataRaw);
        $action = self::normalizeAction((string) ($arguments['action'] ?? ''), $payloadHint);
        if ($payloadHint !== []) {
            $dataRaw = json_encode($payloadHint, JSON_THROW_ON_ERROR);
        }

        if (!$this->tableExists($tableName)) {
            throw new \InvalidArgumentException('Table not found: ' . $tableName);
        }

        // The table comes first: a user who may not change this kind of record must hear that, not a page error.
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication && !(new RecordAccessGate())->canModifyTable($user, $tableName)) {
            throw new \InvalidArgumentException('You are not allowed to change this kind of record with your backend account.');
        }

        $payload = self::decodeData($dataRaw, $action);
        $beforeUid = (int) ($arguments['beforeUid'] ?? $payload['beforeUid'] ?? 0);
        $afterUid = (int) ($arguments['afterUid'] ?? $payload['afterUid'] ?? 0);
        if ($action === 'create') {
            $query = (string) ($arguments['requestQuery'] ?? '');
            $beforeUid = $this->beforeUidFromRequest($beforeUid, $payload, $query);
            $afterUid = $this->afterUidFromRequest($afterUid, $payload, $query);
            if (PageCreateBefore::isCreateBefore($query)) {
                $afterUid = 0;
            }
            if (PageCreateAfter::isCreateAfter($query) || PageCreateAfter::isLastUnder($query)) {
                $beforeUid = 0;
            }
        }

        return match ($action) {
            'create' => $this->planCreate($tableName, $payload, $beforeUid, $afterUid, (string) ($arguments['requestQuery'] ?? '')),
            'update' => $this->planUpdate($tableName, $uid, $payload, (string) ($arguments['requestQuery'] ?? '')),
            'delete' => $this->planDelete($tableName, $uid),
        };
    }

    #[McpTool(
        name: 'write_table',
        description: 'Create, update, or delete records in a TYPO3 table via DataHandler.'
            . ' Use table_schema first to discover valid field names and types.'
            . ' For create, include "pid" in the JSON data object. A positive pid creates the record as the first child of that page.'
            . ' A negative pid (-uid) creates it directly after that record.'
            . ' To place a new record directly after an existing one, pass afterUid of that record. afterUid is used instead of pid.'
            . ' To place a new record directly before an existing one, pass beforeUid of that record. beforeUid is used instead of pid.'
            . ' "Last under a page" is afterUid of that page\'s last child. "Subpage of page 68" is {"pid": 68}.'
            . ' For update/delete, pass the record uid.'
            . ' strict=true refuses the whole call and names the fields that are unknown or that you may not change; by default they are dropped and reported in "ignoredFields".'
            . ' Category/MM relations (categories, authors, tags, …) accept a comma-separated UID string, e.g. "126" or "8,12".'
            . ' File/image fields accept [{"uid_local": <sys_file uid>, "alternative": "..."}]'
            . ' (full replace on update; empty array clears attachments).'
            . ' Content Blocks collections: write child rows in the collection table with foreign_table_parent_uid,'
            . ' not the parent counter field. Prefer content_delete when deleting a tt_content element.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
        ),
    )]
    public function execute(
        #[Schema(enum: ['create', 'update', 'delete'])]
        string $action,
        string $tableName,
        string $data = '{}',
        int $uid = 0,
        bool $strict = false,
        int $beforeUid = 0,
        int $afterUid = 0,
    ): string {
        $payloadHint = self::decodeDataLoose($data);
        try {
            $action = self::normalizeAction($action, $payloadHint);
        } catch (\InvalidArgumentException $exception) {
            return $this->encodeError($exception->getMessage());
        }
        if ($payloadHint !== []) {
            $data = json_encode($payloadHint, JSON_THROW_ON_ERROR);
        }

        if (!$this->tableExists($tableName)) {
            return $this->encodeError('Table not found: ' . $tableName);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->encodeError('No backend user context. Authenticate via OAuth or stdio --user.');
        }

        if (!$backendUser->check('tables_modify', $tableName)) {
            return $this->encodeError('Permission denied: tables_modify on ' . $tableName);
        }

        try {
            $payload = self::decodeData($data, $action);
        } catch (\InvalidArgumentException $exception) {
            return $this->encodeError($exception->getMessage());
        }

        return match ($action) {
            'create' => $this->create($tableName, $payload, $strict, $beforeUid, $afterUid),
            'update' => $this->update($tableName, $uid, $payload, $strict),
            'delete' => $this->delete($tableName, $uid),
        };
    }

    /**
     * The "data" JSON object. A delete needs none (empty or invalid data is ignored there);
     * empty data is an empty object.
     *
     * Map model synonyms to create|update|delete. Unhide/hide become update and set hidden when missing.
     *
     * @param array<string, mixed> $payload
     * @return 'create'|'update'|'delete'
     */
    public static function normalizeAction(string $action, array &$payload): string
    {
        $normalized = strtolower(trim($action));
        if ($normalized === 'create' || $normalized === 'update' || $normalized === 'delete') {
            return $normalized;
        }

        $unhide = ['unhide', 'show', 'enable', 'reveal', 'visible', 'activate', 'publish', 'make_visible'];
        $hide = ['hide', 'disable', 'unpublish'];
        $update = ['change', 'modify', 'set', 'edit'];

        // "make visible" / "make_visible" arrive with a space or underscore.
        $compact = str_replace([' ', '-'], '_', $normalized);
        if (in_array($compact, ['make_visible', 'set_visible', 'make_visible_on_website'], true)) {
            $normalized = 'visible';
        }

        if (in_array($normalized, $unhide, true) || $normalized === 'visible') {
            if (!array_key_exists('hidden', $payload)) {
                $payload['hidden'] = 0;
            }

            return 'update';
        }
        if (in_array($normalized, $hide, true)) {
            if (!array_key_exists('hidden', $payload)) {
                $payload['hidden'] = 1;
            }

            return 'update';
        }
        if (in_array($normalized, $update, true)) {
            return 'update';
        }

        throw new \InvalidArgumentException('Invalid action. Use create, update, or delete.');
    }

    /**
     * Best-effort decode for action aliases; empty on bad JSON (normalizeAction still runs).
     *
     * @return array<string, mixed>
     */
    public static function decodeDataLoose(string $data): array
    {
        try {
            return self::decodeData($data === '' ? '{}' : $data, 'update');
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when create/update data is not a JSON object
     */
    public static function decodeData(string $data, string $action): array
    {
        if ($action === 'delete' || trim($data) === '') {
            return [];
        }
        // A model sometimes wraps the object in a code fence.
        $data = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($data)));
        try {
            try {
                $payload = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                if (!in_array($exception->getCode(), [JSON_ERROR_CTRL_CHAR, JSON_ERROR_SYNTAX], true)) {
                    throw $exception;
                }
                // Some models (Mistral) put raw line breaks or tabs, or unescaped quotes (HTML attributes), inside a string value.
                try {
                    $payload = json_decode(self::escapeControlCharsInStrings($data), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $payload = json_decode(self::escapeControlCharsInStrings(self::escapeInnerQuotes($data)), true, 512, JSON_THROW_ON_ERROR);
                }
            }
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                'data must be a JSON object of field values, e.g. {"pid": 12, "title": "News"} (' . $exception->getMessage() . ').',
                1790400001,
                $exception,
            );
        }
        // Double-encoded: the object arrived as a JSON string holding JSON.
        if (is_string($payload) && in_array(trim($payload)[0] ?? '', ['{'], true)) {
            $inner = json_decode($payload, true);
            $payload = is_array($inner) ? $inner : $payload;
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new \InvalidArgumentException('data must be a JSON object of field values, e.g. {"pid": 12, "title": "News"}.', 1790400002);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * A string only ends at a quote that is followed by `}`, `]`, `:` or `, "`. Any other quote inside
     * a string value (for example in <a href="…">) is escaped.
     */
    private static function escapeInnerQuotes(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if (!$inString) {
                $out .= $char;
                if ($char === '"') {
                    $inString = true;
                    $escaped = false;
                }
                continue;
            }
            if ($escaped) {
                $escaped = false;
                $out .= $char;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                $out .= $char;
                continue;
            }
            if ($char !== '"') {
                $out .= $char;
                continue;
            }
            $rest = ltrim(substr($json, $i + 1));
            $next = $rest[0] ?? '';
            $ends = $next === '' || $next === '}' || $next === ']' || $next === ':'
                || ($next === ',' && preg_match('/^,\s*["}\]\[{]/', $rest) === 1);
            if ($ends) {
                $inString = false;
                $out .= $char;
            } else {
                $out .= '\\"';
            }
        }

        return $out;
    }

    /** Escapes raw control characters inside JSON string literals only; whitespace between tokens stays. */
    private static function escapeControlCharsInStrings(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($inString && !$escaped && ord($char) < 0x20) {
                $out .= sprintf('\\u%04x', ord($char));
                continue;
            }
            if ($inString) {
                $escaped = !$escaped && $char === '\\';
                if (!$escaped && $char === '"') {
                    $inString = false;
                }
            } elseif ($char === '"') {
                $inString = true;
            }
            $out .= $char;
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    private function planCreate(string $tableName, array $payload, int $beforeUid = 0, int $afterUid = 0, string $requestQuery = ''): ToolPlan
    {
        if ($tableName === 'tx_news_domain_model_news') {
            $payload = self::withSaneNewsDate($payload, $requestQuery);
            $payload = $this->withNewsStoragePid($payload);
        }
        $pid = $this->resolveCreatePid($payload, $beforeUid, $afterUid, $tableName);
        if ($pid < 0) {
            $this->recordService->assertInsertAfterExists($tableName, abs($pid));
        }
        if (!$this->targetIsReadable($tableName, $pid)) {
            throw new \InvalidArgumentException(PageAccessService::ACCESS_DENIED_MESSAGE);
        }
        unset($payload['pid'], $payload['beforeUid'], $payload['afterUid']);
        $filteredData = self::withoutEmptyCreateFields($this->normalizer->filterWritableFields($tableName, $payload));
        if ($tableName === 'tt_content' && ($filteredData['CType'] ?? '') === 'text'
            && trim((string) ($filteredData['header'] ?? '')) === ''
            && trim((string) ($filteredData['subheader'] ?? '')) === ''
            && trim((string) ($filteredData['bodytext'] ?? '')) === ''
        ) {
            throw new \InvalidArgumentException('A text element needs a header or a text. Write the text first and send it in the same call.');
        }
        // Fields the group may not edit would be dropped silently at Apply: the card must not offer them,
        // and a create whose requested content fields are all forbidden is refused.
        $notAllowed = [];
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication && $filteredData !== []) {
            $allowed = (new RecordAccessGate())->withoutForbiddenFields($user, $tableName, $filteredData);
            $notAllowed = array_values(array_map('strval', array_keys(array_diff_key($filteredData, $allowed))));
            if ($notAllowed !== [] && array_diff(array_keys($allowed), self::STRUCTURAL_FIELDS) === []) {
                throw new \InvalidArgumentException('You are not allowed to change this field with your backend account: ' . implode(', ', $notAllowed) . '.');
            }
            // A page type the group may not set is dropped at Apply, and the editor would get a plain page.
            if ($tableName === 'pages' && (int) ($filteredData['doktype'] ?? 1) !== 1 && !isset($allowed['doktype'])) {
                throw new \InvalidArgumentException(sprintf(
                    'You are not allowed to create this kind of page (page type %s) with your backend account. Nothing was created.',
                    (string) $filteredData['doktype'],
                ));
            }
            if (
                $tableName === 'pages'
                && (int) ($allowed['doktype'] ?? 1) !== 1
                && !$user->isAdmin()
                && !$user->checkAuthMode('pages', 'doktype', (string) $allowed['doktype'])
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'You are not allowed to create this kind of page (page type %s) with your backend account. Nothing was created.',
                    (string) $allowed['doktype'],
                ));
            }
            $filteredData = $allowed;
        }

        $fields = [];
        foreach ($filteredData as $fieldName => $value) {
            $fields[] = new ToolPlanField(
                ToolPlanField::buildKey($tableName, 0, $fieldName),
                $tableName,
                0,
                $fieldName,
                null,
                $value,
            );
        }

        return new ToolPlan('create', 'write_table', $fields, ['pid' => $pid] + ($notAllowed !== [] ? ['notAllowedFields' => $notAllowed] : []));
    }

    /**
     * A new record starts empty, so a blank value or a 0 on a field that is 0 anyway only adds an empty row to the
     * card (and, for a model that sends every column, buries the text the editor wants to review).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function withoutEmptyCreateFields(array $data): array
    {
        foreach ($data as $field => $value) {
            $blank = $value === null || (is_string($value) && trim($value) === '');
            $zeroDefault = ($value === 0 || $value === '0') && in_array((string) $field, self::ZERO_IS_DEFAULT_FIELDS, true);
            if ($blank || $zeroDefault) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function planUpdate(string $tableName, int $uid, array $payload, string $requestQuery = ''): ToolPlan
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Update requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        $filteredData = $this->normalizer->filterWritableFields($tableName, $payload);
        if ($filteredData === []) {
            throw new \InvalidArgumentException($this->normalizer->noWritableFieldsMessage($tableName, $payload, $filteredData));
        }
        // A field the group may not edit would only fail at Apply: say so now, before a card is shown.
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication) {
            $allowed = (new RecordAccessGate())->withoutForbiddenFields($user, $tableName, $filteredData);
            if ($allowed === []) {
                throw new \InvalidArgumentException('You are not allowed to change this field with your backend account: ' . implode(', ', array_keys($filteredData)) . '.');
            }
            $filteredData = $allowed;
        }

        $fieldNames = array_keys($filteredData);
        $current = $this->recordService->findByUid($tableName, $uid, $fieldNames) ?? [];

        // A rewrite keeps the facts of the original. A draft that shares nothing with it is refused here, so
        // the model corrects the call instead of the editor approving an unrelated text.
        if (self::isRewriteRequest($requestQuery)) {
            foreach (self::REWRITE_CHECKED_FIELDS as $textField) {
                if (isset($filteredData[$textField], $current[$textField]) && is_string($filteredData[$textField]) && is_string($current[$textField])
                    && !self::rewriteKeepsMeaning($current[$textField], $filteredData[$textField])
                ) {
                    throw new \InvalidArgumentException(sprintf(
                        'The new "%s" shares none of the key words of the current text. A rewrite must keep the meaning and every fact of the original and only change wording, tone or length. Rewrite the current text again and call write_table with that.',
                        $textField,
                    ));
                }
            }
        }

        $fields = [];
        foreach ($filteredData as $fieldName => $value) {
            $fields[] = new ToolPlanField(
                ToolPlanField::buildKey($tableName, $uid, $fieldName),
                $tableName,
                $uid,
                $fieldName,
                $current[$fieldName] ?? null,
                $value,
            );
        }

        foreach ($this->hiddenTranslationContentFields($tableName, $uid, $filteredData, $requestQuery) as $extra) {
            $fields[] = $extra;
        }

        return new ToolPlan('update', 'write_table', $fields);
    }

    /**
     * Unhiding a translated page also unhides its hidden content elements (same as Activate Translated Content).
     *
     * @param array<string, mixed> $filteredData
     * @return list<ToolPlanField>
     */
    private function hiddenTranslationContentFields(string $tableName, int $uid, array $filteredData, string $requestQuery): array
    {
        if ($tableName !== 'pages' || !array_key_exists('hidden', $filteredData) || (int) $filteredData['hidden'] !== 0) {
            return [];
        }
        $page = $this->recordService->findByUid('pages', $uid, ['uid', 'pid', 'sys_language_uid', 'l10n_parent', 'hidden']);
        if (!is_array($page)) {
            return [];
        }
        $languageUid = (int) ($page['sys_language_uid'] ?? 0);
        if ($languageUid <= 0) {
            return [];
        }
        // Only when making a translation visible (explicit request or unhiding a hidden translation row).
        $asksVisible = $requestQuery === ''
            || preg_match('/\b(visible|unhide|show|enable|activate|sichtbar|einblend|aktivier)\w*\b/iu', $requestQuery) === 1;
        if (!$asksVisible) {
            return [];
        }

        // Connected translations keep content on the default-language page (l10n_parent).
        $contentPid = (int) ($page['l10n_parent'] ?? 0);
        if ($contentPid <= 0) {
            $contentPid = $uid;
        }

        $listed = $this->recordService->findByPid(
            'tt_content',
            $contentPid,
            100,
            0,
            ['uid', 'hidden', 'deleted', 'header'],
            $languageUid,
            'sys_language_uid',
        );
        $fields = [];
        foreach ($listed['records'] as $row) {
            if (!is_array($row) || (int) ($row['deleted'] ?? 0) === 1 || (int) ($row['hidden'] ?? 0) !== 1) {
                continue;
            }
            $contentUid = (int) ($row['uid'] ?? 0);
            if ($contentUid <= 0) {
                continue;
            }
            $fields[] = new ToolPlanField(
                ToolPlanField::buildKey('tt_content', $contentUid, 'hidden'),
                'tt_content',
                $contentUid,
                'hidden',
                1,
                0,
            );
        }

        return $fields;
    }

    /**
     * News create needs a storage folder pid. Models omit it (or send 0) after being told not to use the
     * Layout page for news search — DataHandler then fails on pages:0.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withNewsStoragePid(array $payload): array
    {
        $pid = isset($payload['pid']) && is_numeric($payload['pid']) ? (int) $payload['pid'] : 0;
        // Keep only a real news storage folder. A guessed Layout page (e.g. Privacy) must not win.
        if ($pid > 0 && $this->recordService->isSuitableNewsStoragePid($pid)) {
            return $payload;
        }

        $fallback = $this->recordService->preferredPidForTable('tx_news_domain_model_news');
        if ($fallback <= 0) {
            throw new \InvalidArgumentException(
                'Creating a news article needs a news storage folder (pid). None was found that you can use.',
            );
        }
        $payload['pid'] = $fallback;

        return $payload;
    }

    /**
     * True when create data has no usable pid yet (missing/zero). Unsuitable positive pids are
     * rewritten in {@see withNewsStoragePid()} via page type, not here.
     *
     * @param array<string, mixed> $payload
     * @internal used by unit tests
     */
    public static function newsCreateNeedsStoragePid(array $payload): bool
    {
        return !isset($payload['pid']) || !is_numeric($payload['pid']) || (int) $payload['pid'] <= 0;
    }

    /**
     * A new news article is dated today unless the editor named a date. Models invent one (2023-12-01) that
     * sorts the article years into the past.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function withSaneNewsDate(array $payload, string $requestQuery, ?int $now = null): array
    {
        $now ??= time();
        $given = $payload['datetime'] ?? null;
        if ($given === null || $given === '' || $given === 0 || $given === '0') {
            $payload['datetime'] = date('Y-m-d H:i:s', $now);

            return $payload;
        }
        $timestamp = is_numeric($given) ? (int) $given : strtotime((string) $given);
        $namedByEditor = preg_match('/\b(?:19|20)\d{2}\b|\d{1,2}\.\d{1,2}\.|\b(?:today|tomorrow|yesterday|heute|morgen|gestern)\b/iu', $requestQuery) === 1;
        if (!$namedByEditor && ($timestamp === false || $timestamp < $now - 86400 * 183)) {
            $payload['datetime'] = date('Y-m-d H:i:s', $now);
        }

        return $payload;
    }

    public static function isRewriteRequest(string $query): bool
    {
        return preg_match(
            '/\b(rewrite|reword|rephrase|paraphrase|shorten|simplify|polish|improve|friendlier|more\s+friendly|more\s+formal|shorter|longer|simpler|k[üu]rzer|einfacher|umschreib\w*|umformulier\w*|k[üu]rz\w*|freundlicher|verbesser\w*|[üu]berarbeit\w*)\b/iu',
            $query,
        ) === 1;
    }

    /**
     * False when the new text shares (almost) none of the significant words of the old one, i.e. it is not a
     * rewrite of it. Short originals (fewer than 3 significant words) cannot be judged and pass.
     */
    public static function rewriteKeepsMeaning(string $old, string $new): bool
    {
        $oldWords = self::significantWords($old);
        if (count($oldWords) < 3) {
            return true;
        }
        $newWords = self::significantWords($new);
        $kept = 0;
        foreach ($oldWords as $word) {
            foreach ($newWords as $candidate) {
                if ($candidate === $word || (mb_strlen($word) >= 5 && mb_strlen($candidate) >= 5 && mb_substr($candidate, 0, 5) === mb_substr($word, 0, 5))) {
                    ++$kept;
                    break;
                }
            }
        }

        return $kept / count($oldWords) >= 0.34;
    }

    /** @return list<string> */
    private static function significantWords(string $text): array
    {
        $plain = mb_strtolower(html_entity_decode(strip_tags($text)));
        preg_match_all('/[\p{L}\p{N}]{4,}/u', $plain, $matches);
        $stop = ['this', 'that', 'with', 'from', 'your', 'have', 'will', 'dies', 'diese', 'dieser', 'eine', 'einen', 'sind', 'oder', 'sowie'];

        return array_values(array_unique(array_diff($matches[0], $stop)));
    }

    private function planDelete(string $tableName, int $uid): ToolPlan
    {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('Delete requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            throw new \InvalidArgumentException('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        return new ToolPlan('delete', 'write_table', [
            new ToolPlanField(
                ToolPlanField::buildKey($tableName, $uid, '_record'),
                $tableName,
                $uid,
                '_record',
                'exists',
                'delete',
            ),
        ]);
    }

    /** Refuses a create target page the backend user cannot read (negative pid = after record uid). */
    private function targetIsReadable(string $tableName, int $pid): bool
    {
        if ($this->pageAccess === null || $this->pageAccess->isUnrestricted() || $pid === 0) {
            return true;
        }

        return $pid < 0
            ? $this->recordService->findByUid($tableName, abs($pid), ['uid']) !== null
            : $this->pageAccess->canReadPage($pid);
    }

    /**
     * The editor asked to create a page before another page. That page wins over a parent pid
     * and over a negative pid, which would place the new page after it.
     *
     * @param array<string, mixed> $payload
     */
    private function beforeUidFromRequest(int $beforeUid, array $payload, string $query): int
    {
        if ($beforeUid > 0 || !PageCreateBefore::isCreateBefore($query)) {
            return $beforeUid;
        }

        $named = $this->namedPageUid(PageCreateBefore::target($query), $payload);

        return $named > 0 ? $named : $beforeUid;
    }

    /**
     * The editor asked to create a page after another page, or as the last child of one.
     * That place wins over a positive pid, which would make the new page a subpage.
     *
     * @param array<string, mixed> $payload
     */
    private function afterUidFromRequest(int $afterUid, array $payload, string $query): int
    {
        if (PageCreateAfter::isLastUnder($query)) {
            $parentUid = $this->namedPageUid(PageCreateAfter::lastUnderTarget($query), $payload);
            if ($parentUid <= 0) {
                return $afterUid;
            }
            $lastChild = $this->recordService->lastDefaultLanguageChildUid($parentUid);

            return $lastChild > 0 ? $lastChild : $afterUid;
        }
        if (!PageCreateAfter::isCreateAfter($query)) {
            return $afterUid;
        }

        $named = $this->namedPageUid(PageCreateAfter::target($query), $payload);

        return $named > 0 ? $named : $afterUid;
    }

    /**
     * @param array{title: string, uid: int} $target
     * @param array<string, mixed> $payload
     */
    private function namedPageUid(array $target, array $payload): int
    {
        if ($target['uid'] > 0) {
            return $target['uid'];
        }

        $pid = isset($payload['pid']) && is_numeric($payload['pid']) ? (int) $payload['pid'] : 0;
        if ($pid !== 0 && $target['title'] !== '') {
            $anchor = $this->recordService->findByUid('pages', abs($pid), ['title']);
            $title = is_array($anchor) ? trim((string) ($anchor['title'] ?? '')) : '';
            if ($title !== '' && strcasecmp($title, $target['title']) === 0) {
                return abs($pid);
            }
        }
        if ($target['title'] === '') {
            return 0;
        }

        $matches = $this->recordService->findDefaultLanguagePagesByTitle($target['title']);
        if (count($matches) === 1) {
            return $matches[0]['uid'];
        }
        if ($pid > 0) {
            $underParent = array_values(array_filter(
                $matches,
                static fn(array $page): bool => $page['pid'] === $pid,
            ));
            if (count($underParent) === 1) {
                return $underParent[0]['uid'];
            }
        }

        return 0;
    }

    /**
     * afterUid places the new record directly after that page and wins over a positive pid.
     * beforeUid places it directly before that page.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveCreatePid(array $payload, int $beforeUid, int $afterUid = 0, string $tableName = 'pages'): int
    {
        $beforeUid = $beforeUid > 0 ? $beforeUid : (int) ($payload['beforeUid'] ?? 0);
        $afterUid = $afterUid > 0 ? $afterUid : (int) ($payload['afterUid'] ?? 0);
        if ($afterUid > 0 && $beforeUid <= 0) {
            return -$afterUid;
        }
        if ($beforeUid > 0) {
            // "Before 773" for a content element means that element, never the page with uid 773.
            if ($tableName !== 'pages' && $this->recordService->findExistingUids($tableName, [$beforeUid]) !== []) {
                return $this->recordService->targetBeforeRecord($tableName, $beforeUid);
            }

            return $this->recordService->targetBeforePage($beforeUid);
        }
        if (!isset($payload['pid']) || !is_numeric($payload['pid'])) {
            throw new \InvalidArgumentException('Create requires numeric "pid" in data.');
        }

        return (int) $payload['pid'];
    }

    /** @param array<string, mixed> $payload */
    private function create(string $tableName, array $payload, bool $strict = false, int $beforeUid = 0, int $afterUid = 0): string
    {
        try {
            if ($tableName === 'tx_news_domain_model_news') {
                $payload = $this->withNewsStoragePid($payload);
            }
            $pid = $this->resolveCreatePid($payload, $beforeUid, $afterUid, $tableName);
        } catch (\InvalidArgumentException $exception) {
            return $this->encodeError($exception->getMessage());
        }
        unset($payload['beforeUid'], $payload['afterUid']);
        if (!$this->targetIsReadable($tableName, $pid)) {
            return $this->encodeError(PageAccessService::ACCESS_DENIED_MESSAGE);
        }
        unset($payload['pid']);

        [$payload, $fileFields] = $this->normalizer->extractFileFields($tableName, $payload);
        $payload = $this->normalizer->normalizeRelationUidListFields($tableName, $payload);
        $filteredData = $this->normalizer->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->normalizer->ignoredFields($payload, $filteredData);

        if ($strict && $ignoredFields !== []) {
            return $this->encodeError(
                'Strict mode: these fields are unknown or not writable: ' . implode(', ', $ignoredFields) . '. Nothing was written.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        $unreadable = $this->unreadableFile($fileFields);
        if ($unreadable !== null) {
            return $this->encodeError($unreadable);
        }

        if ($filteredData === [] && $fileFields === []) {
            return $this->encodeError(
                'No valid writable fields provided.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        try {
            $result = $this->write([$tableName => [self::NEW_ID => ['pid' => $pid] + $filteredData]], [], $strict);
            $newUid = $result->created[self::NEW_ID] ?? 0;
            if ($newUid <= 0) {
                throw new \RuntimeException('Failed to create record: no uid returned', 1712000020);
            }

            $fileFieldUids = $this->applyFileFields($tableName, $newUid, $fileFields);
        } catch (\Throwable $exception) {
            return $this->encodeError($this->describe($exception));
        }

        [$writtenFields, $ignoredFields] = $this->withEngineIgnored($filteredData, $ignoredFields, $result);

        $response = [
            'action' => 'create',
            'table' => $tableName,
            'uid' => $newUid,
            'pid' => $pid,
            'fields' => $writtenFields,
            'ignoredFields' => $ignoredFields,
        ];
        if ($ignoredFields !== []) {
            $response['ignoredFieldDetails'] = $this->normalizer->ignoredFieldDetails($tableName, $ignoredFields);
        }
        if ($fileFieldUids !== []) {
            $response['fileFields'] = $fileFieldUids;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function update(string $tableName, int $uid, array $payload, bool $strict = false): string
    {
        if ($uid <= 0) {
            return $this->encodeError('Update requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            return $this->encodeError('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        [$payload, $fileFields] = $this->normalizer->extractFileFields($tableName, $payload);
        $payload = $this->normalizer->normalizeRelationUidListFields($tableName, $payload);
        $filteredData = $this->normalizer->filterWritableFields($tableName, $payload);
        $ignoredFields = $this->normalizer->ignoredFields($payload, $filteredData);

        if ($strict && $ignoredFields !== []) {
            return $this->encodeError(
                'Strict mode: these fields are unknown or not writable: ' . implode(', ', $ignoredFields) . '. Nothing was written.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        $unreadable = $this->unreadableFile($fileFields);
        if ($unreadable !== null) {
            return $this->encodeError($unreadable);
        }

        if ($filteredData === [] && $fileFields === []) {
            return $this->encodeError(
                'No valid writable fields provided.',
                $this->normalizer->ignoredFieldsContext($tableName, $ignoredFields),
            );
        }

        $result = null;

        try {
            if ($filteredData !== []) {
                $result = $this->write([$tableName => [$uid => $filteredData]], [], $strict);
            }
            $fileFieldUids = $this->applyFileFields($tableName, $uid, $fileFields);
        } catch (\Throwable $exception) {
            return $this->encodeError($this->describe($exception));
        }

        [$writtenFields, $ignoredFields] = $this->withEngineIgnored($filteredData, $ignoredFields, $result);

        $response = [
            'action' => 'update',
            'table' => $tableName,
            'uid' => $uid,
            'fields' => $writtenFields,
            'ignoredFields' => $ignoredFields,
        ];
        if ($ignoredFields !== []) {
            $response['ignoredFieldDetails'] = $this->normalizer->ignoredFieldDetails($tableName, $ignoredFields);
        }
        if ($fileFieldUids !== []) {
            $response['fileFields'] = $fileFieldUids;
        }
        if ($result instanceof RecordsApplyResult) {
            if ($result->warnings !== []) {
                $response['warnings'] = $result->warnings;
            }
            if ($result->redirects !== []) {
                $response['redirects'] = $result->redirects;
            }
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    private function delete(string $tableName, int $uid): string
    {
        if ($uid <= 0) {
            return $this->encodeError('Delete requires uid > 0.');
        }

        if ($this->recordService->findExistingUids($tableName, [$uid]) === []) {
            return $this->encodeError('Record not found: ' . $tableName . ' uid ' . $uid);
        }

        try {
            $this->write([], [$tableName => [$uid => ['delete' => 1]]]);
        } catch (\Throwable $exception) {
            return $this->encodeError($this->describe($exception));
        }

        return json_encode([
            'action' => 'delete',
            'table' => $tableName,
            'uid' => $uid,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, array<int, array<string, int>>> $cmdmap
     */
    private function write(array $datamap, array $cmdmap, bool $strict = false): RecordsApplyResult
    {
        return $this->recordsApply->apply($datamap, $cmdmap, false, $strict, true, [], 'write_table');
    }

    /**
     * Fields the engine dropped on top of those the normalizer already ignored.
     *
     * @param array<string, mixed> $filteredData
     * @param list<string> $ignoredFields
     * @return array{0: list<string>, 1: list<string>} the fields that were written, the fields that were ignored
     */
    private function withEngineIgnored(array $filteredData, array $ignoredFields, ?RecordsApplyResult $result): array
    {
        $dropped = [];
        foreach ($result->ignoredFields ?? [] as $entry) {
            $dropped = array_merge($dropped, $entry['fields']);
        }

        return [
            array_values(array_diff(array_keys($filteredData), $dropped)),
            array_values(array_unique(array_merge($ignoredFields, $dropped))),
        ];
    }

    /** The message write_table has always returned, built from what the engine refused with. */
    private function describe(\Throwable $exception): string
    {
        if ($exception instanceof RecordsApplyValidationException) {
            $parts = [];
            foreach ($exception->getProblems() as $problem) {
                $text = (string) ($problem['error'] ?? '');
                $fields = $problem['fields'] ?? [];
                if (is_array($fields) && $fields !== []) {
                    $text .= ' (' . implode(', ', array_map('strval', $fields)) . ')';
                }
                $parts[] = $text;
            }

            return implode('; ', array_slice($parts, 0, 5));
        }

        if ($exception instanceof ToolCallException) {
            $payload = json_decode($exception->getMessage(), true);
            if (is_array($payload) && isset($payload['errors']) && is_array($payload['errors'])) {
                return 'DataHandler errors: ' . implode('; ', array_map('strval', $payload['errors']));
            }
        }

        return $exception->getMessage();
    }

    /**
     * The first file reference that points at a file the backend user may not read, as an error message.
     *
     * @param array<string, list<array{uid_local: int}>> $fileFields
     */
    private function unreadableFile(array $fileFields): ?string
    {
        if ($this->fileAccess === null) {
            return null;
        }

        foreach ($fileFields as $references) {
            foreach ($references as $reference) {
                $fileUid = (int) ($reference['uid_local'] ?? 0);
                if ($fileUid > 0 && !$this->fileAccess->canRead($fileUid)) {
                    return sprintf('File %d was not found or is not accessible to you. Nothing was written.', $fileUid);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, list<array{uid_local: int, alternative?: string, title?: string, description?: string, link?: string, crop?: string}>> $fileFields
     * @return array<string, list<int>>
     */
    private function applyFileFields(string $tableName, int $recordUid, array $fileFields): array
    {
        $result = [];
        foreach ($fileFields as $fieldName => $references) {
            $result[$fieldName] = $this->dataHandlerService->replaceFileFieldReferences(
                $tableName,
                $recordUid,
                $fieldName,
                $references,
            );
        }

        return $result;
    }

    private function tableExists(string $tableName): bool
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return false;
        }

        return isset($tca[$tableName]) && is_array($tca[$tableName]);
    }

    /** @param array<string, mixed> $context */
    private function encodeError(string $message, array $context = []): string
    {
        return json_encode(array_merge(['error' => $message], $context), JSON_THROW_ON_ERROR);
    }
}
