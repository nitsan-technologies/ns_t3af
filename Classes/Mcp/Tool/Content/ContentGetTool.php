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

namespace NITSAN\NsT3AF\Mcp\Tool\Content;

use const JSON_THROW_ON_ERROR;

use Mcp\Capability\Attribute\McpTool;
use NITSAN\NsT3AF\Mcp\Contract\McpNonAiToolInterface;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;

readonly class ContentGetTool implements McpNonAiToolInterface
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {}

    #[McpTool(name: 'content_get', description: 'Get a single content element by its uid.')]
    public function execute(int $uid): string
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig('tt_content');
        $fields = $this->tcaSchemaService->getReadFields('tt_content');

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        $record = $this->recordService->findByUid('tt_content', $uid, $fields);

        if ($record === null) {
            return json_encode(['error' => 'Content element not found'], JSON_THROW_ON_ERROR);
        }

        $sysLanguageUid = $record[$languageField ?? ''] ?? -1;
        if (
            $languageField !== null
            && $transOrigPointerField !== null
            && (is_int($sysLanguageUid) || is_string($sysLanguageUid))
            && (int) $sysLanguageUid === 0
        ) {
            $record['translations'] = $this->recordService->findTranslations(
                'tt_content',
                $uid,
                $languageField,
                $transOrigPointerField,
            );
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
