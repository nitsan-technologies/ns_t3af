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
    public const SEO_PAGE_METADATA = 'seo_page_metadata';
    public const SEO_IMAGE_METADATA = 'seo_image_metadata';
    public const CONTENT_GENERATE = 'content_generate';
    public const CONTENT_TRANSLATE = 'content_translate';
    public const CONTENT_PAGE_STRUCTURE = 'content_page_structure';
    public const EASY_LANGUAGE = 'easy_language';
    public const ASSISTANT_CHAT = 'assistant_chat';
    public const EMBEDDING = 'embedding';
    public const TEXT_TO_SPEECH = 'text_to_speech';
    public const IMAGE_GENERATE = 'image_generate';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SEO_PAGE_METADATA,
            self::SEO_IMAGE_METADATA,
            self::CONTENT_GENERATE,
            self::CONTENT_TRANSLATE,
            self::CONTENT_PAGE_STRUCTURE,
            self::EASY_LANGUAGE,
            self::ASSISTANT_CHAT,
            self::EMBEDDING,
            self::TEXT_TO_SPEECH,
            self::IMAGE_GENERATE,
        ];
    }

    public static function isCatalogKey(string $featureKey): bool
    {
        return in_array($featureKey, self::all(), true);
    }
}
