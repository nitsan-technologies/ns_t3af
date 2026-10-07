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

namespace NITSAN\NsT3AF\Mcp\Tool\Pages;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Attribute\McpToolSeverity;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Enum\ToolSeverity;
use NITSAN\NsT3AF\Mcp\Service\PageAccessService;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

#[McpToolSeverity(ToolSeverity::Read)]
readonly class PagesGetTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
        private PageAccessService $pageAccess,
    ) {}

    #[McpTool(
        name: 'pages_get',
        description: 'Get a single page by its uid.'
            . ' When uid is omitted, pageId may be used (agent context supplies the current page).'
            . ' Use selectFields (comma-separated) to choose which fields to return.',
    )]
    public function execute(int $uid = 0, string $selectFields = '', int $pageId = 0): string
    {
        $resolvedUid = $uid > 0 ? $uid : $pageId;
        if ($resolvedUid <= 0) {
            return json_encode(['error' => 'Missing required argument: uid (or pageId)'], JSON_THROW_ON_ERROR);
        }

        if (!$this->pageAccess->canReadPage($resolvedUid)) {
            return json_encode(['error' => PageAccessService::ACCESS_DENIED_MESSAGE], JSON_THROW_ON_ERROR);
        }

        $translationConfig = $this->tcaSchemaService->getTranslationConfig('pages');
        $fields = $this->resolveSelectFields($selectFields);

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        $record = $this->recordService->findByUid('pages', $resolvedUid, $fields);

        if ($record === null) {
            return json_encode(['error' => 'Page not found'], JSON_THROW_ON_ERROR);
        }

        $sysLanguageUid = $record[$languageField ?? ''] ?? -1;
        if (
            $languageField !== null
            && $transOrigPointerField !== null
            && (
                is_int($sysLanguageUid)
                || is_string($sysLanguageUid)
            )
            && (int) $sysLanguageUid === 0
        ) {
            $record['translations'] = $this->recordService->findTranslations('pages', $resolvedUid, $languageField, $transOrigPointerField);
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function resolveSelectFields(string $selectFields): array
    {
        if ($selectFields === '') {
            return $this->tcaSchemaService->getReadFields('pages');
        }

        $requested = array_map('trim', explode(',', $selectFields));
        $readable = $this->tcaSchemaService->getReadFields('pages');
        $allowed = array_merge(['uid', 'pid'], $readable);
        $valid = array_values(array_intersect($requested, $allowed));

        return $valid !== []
            ? array_values(array_unique(array_merge(['uid', 'pid'], $valid)))
            : $this->tcaSchemaService->getReadFields('pages');
    }
}
