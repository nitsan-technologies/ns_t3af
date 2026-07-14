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

namespace NITSAN\NsT3AF\Credits;

/**
 * Authoritative {@see ns_ai_feature_cost.feature_key} values from the T3Planet composer server.
 *
 * Child extensions may pass extension-specific keys in {@see \NITSAN\NsT3AF\Api\AiOptions};
 * {@see Service\CreditsFeatureKeyMapper} normalizes them before Charge / Stream / Embed.
 */
final class CreditsFeatureKeyCatalog
{
    public const CONTENT_GENERATION = 'content_generation';
    public const CONTENT_TRANSLATION = 'content_translation';
    public const EASY_LANGUAGE = 'easy_language';
    public const EMBEDDING = 'embedding';
    public const IMAGE_GENERATION = 'image_generation';
    public const METADATA_ALT_TEXT = 'metadata_alt_text';
    public const METADATA_DESCRIPTION = 'metadata_description';
    public const METADATA_TITLE = 'metadata_title';
    public const PAGE_STRUCTURE_GENERATION = 'page_structure_generation';
    public const SEO_META_DESCRIPTION = 'seo_meta_description';
    public const SEO_OG_DESCRIPTION = 'seo_og_description';
    public const SEO_OG_TITLE = 'seo_og_title';
    public const SEO_PAGE_TITLE = 'seo_page_title';
    public const STREAM = 'stream';
    public const TEXT_TO_SPEECH = 'text_to_speech';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CONTENT_GENERATION,
            self::CONTENT_TRANSLATION,
            self::EASY_LANGUAGE,
            self::EMBEDDING,
            self::IMAGE_GENERATION,
            self::METADATA_ALT_TEXT,
            self::METADATA_DESCRIPTION,
            self::METADATA_TITLE,
            self::PAGE_STRUCTURE_GENERATION,
            self::SEO_META_DESCRIPTION,
            self::SEO_OG_DESCRIPTION,
            self::SEO_OG_TITLE,
            self::SEO_PAGE_TITLE,
            self::STREAM,
            self::TEXT_TO_SPEECH,
        ];
    }

    public static function isCatalogKey(string $featureKey): bool
    {
        return in_array($featureKey, self::all(), true);
    }
}
