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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

namespace NITSAN\NsT3AF\Mcp\Tool\Pages;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

readonly class PagesListTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(
        name: 'pages_list',
        description: 'List pages by parent page ID with pagination. Use sysLanguageUid to filter by language (0 = default, -1 = all).'
            . ' Use selectFields (comma-separated) to choose which fields to return.',
    )]
    public function execute(int $pid = 0, int $limit = 20, int $offset = 0, int $sysLanguageUid = -1, string $selectFields = ''): string
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig('pages');

        if ($selectFields !== '') {
            $requested = array_map('trim', explode(',', $selectFields));
            $readable = $this->tcaSchemaService->getReadFields('pages');
            $allowed = array_merge(['uid', 'pid'], $readable);
            $valid = array_values(array_intersect($requested, $allowed));
            $fields = $valid !== []
                ? array_values(array_unique(array_merge(['uid', 'pid'], $valid)))
                : $this->tcaSchemaService->getListFields('pages');
        } else {
            $fields = $this->tcaSchemaService->getListFields('pages');
        }

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        $result = $this->recordService->findByPid(
            'pages',
            $pid,
            $limit,
            $offset,
            $fields,
            $sysLanguageUid >= 0 && $languageField !== null ? $sysLanguageUid : null,
            $sysLanguageUid >= 0 ? $languageField : null,
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
