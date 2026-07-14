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
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

return [
    [
        'name' => 'audit_page_seo',
        'description' => 'Audit page SEO fields and optionally apply improvements',
        'templateBody' => 'You are a TYPO3 SEO editor. Audit page {{pid}} (language {{language}}). Steps: 1) Call pages_get for uid {{pid}} and note title, slug, and SEO-related fields. 2) Call table_schema for pages to confirm editable SEO field names. 3) Call content_list for pid {{pid}} with sysLanguageUid {{language}} and check main headings (header fields) for H1/H2 structure. 4) Return a concise audit: current values, issues (missing/weak title or description, slug problems, heading structure), severity, and recommended replacements. 5) If apply_fixes is "{{apply_fixes}}" and true, call write_table action update on pages for uid {{pid}} with only the improved SEO fields; otherwise report only.',
        'arguments' => [
            ['name' => 'pid', 'required' => true, 'description' => 'Page UID to audit'],
            ['name' => 'language', 'required' => false, 'description' => 'Language UID (default: 0)', 'default' => '0'],
            ['name' => 'apply_fixes', 'required' => false, 'description' => 'Apply recommended SEO fixes (default: false)', 'default' => 'false'],
        ],
    ],
    [
        'name' => 'add_content_text_block',
        'description' => 'Add a text content element to a page from an editor brief',
        'templateBody' => 'You are a TYPO3 backend editor. On page {{pid}}, add a new text content element from this brief: "{{brief}}". Steps: 1) Call content_list for pid {{pid}} (sysLanguageUid {{language}}) to inspect existing elements and colPos usage. 2) Call table_schema for tt_content. 3) Draft a clear header and bodytext HTML matching the brief and site tone. 4) Call write_table action create on tt_content with pid {{pid}}, CType text, colPos {{colPos}}, sys_language_uid {{language}}, header, and bodytext. 5) Confirm the new uid and summarize what was created.',
        'arguments' => [
            ['name' => 'brief', 'required' => true, 'description' => 'What the text block should say (topic, tone, CTA)'],
            ['name' => 'pid', 'required' => true, 'description' => 'Page UID where the content element should be added'],
            ['name' => 'colPos', 'required' => false, 'description' => 'Column position (default: 0)', 'default' => '0'],
            ['name' => 'language', 'required' => false, 'description' => 'Target language UID (default: 0)', 'default' => '0'],
        ],
    ],
    [
        'name' => 'translate_page_content',
        'description' => 'Translate content elements on a page into a target language',
        'templateBody' => 'You are a TYPO3 localization editor. Translate page {{pid}} content from language {{source_language}} to language {{target_language}}. Steps: 1) Call site_languages_list with pageId {{pid}} and verify {{target_language}} is available. 2) Call content_list for pid {{pid}} with sysLanguageUid {{source_language}} to load source elements (header, bodytext, CType). 3) Call content_list again with sysLanguageUid {{target_language}} to find existing translations. 4) Call table_schema for tt_content to confirm localization fields (sys_language_uid, l10n_parent). 5) For each translatable element missing in the target language, call write_table action create with l10n_parent set to the source uid; for existing translations, call write_table action update. Preserve HTML structure and TYPO3 links. 6) Return a table of source uid, target uid, and status (created/updated/skipped).',
        'arguments' => [
            ['name' => 'pid', 'required' => true, 'description' => 'Page UID whose content elements should be translated'],
            ['name' => 'target_language', 'required' => true, 'description' => 'Target language UID (e.g. 1 for English)'],
            ['name' => 'source_language', 'required' => false, 'description' => 'Source language UID (default: 0)', 'default' => '0'],
        ],
    ],
];
