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

namespace NITSAN\NsT3AF\Agent\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Resolves the two languages an agent turn has to respect:
 *
 * - the reply language: the language of the editor's message, falling back to
 *   the backend user's language
 * - the backend user's language, used for texts shown without a message (summaries)
 * - the target record's site language, used for content that gets stored
 *
 * @internal
 */
final readonly class AgentLanguageResolver
{
    public const FALLBACK_ISO = 'en';
    public const FALLBACK_NAME = 'English';

    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * The backend user language as ISO code; TYPO3's "default" means English.
     */
    public function resolveBackendIsoCode(?BackendUserAuthentication $user = null): string
    {
        $user ??= $GLOBALS['BE_USER'] ?? null;
        $raw = $user instanceof BackendUserAuthentication ? (string) ($user->user['lang'] ?? '') : '';

        return $this->normalizeIsoCode($raw);
    }

    /**
     * English name of the backend user language, e.g. "German" — prompts stay English.
     */
    public function resolveBackendLanguageName(?BackendUserAuthentication $user = null): string
    {
        return $this->languageName($this->resolveBackendIsoCode($user));
    }

    /**
     * Prompt rule: the editor always reads answers in their own backend language.
     */
    public function backendLanguageInstruction(?BackendUserAuthentication $user = null): string
    {
        return sprintf(
            'Always reply to the editor in %s, even if they write in another language.'
            . ' Keep tool names, field keys and ids unchanged.',
            $this->resolveBackendLanguageName($user),
        );
    }

    /**
     * Prompt rule for chat replies: answer in the language of the editor's message,
     * so an English question gets an English answer even in a German backend.
     * The backend language is only the fallback for messages without language
     * (slash commands, ids, single words).
     */
    public function replyLanguageInstruction(?BackendUserAuthentication $user = null): string
    {
        return sprintf(
            'Reply in the language of the editor\'s latest message.'
            . ' If that language is unclear (for example only a command, a name or ids), reply in %s.'
            . ' Keep tool names, field keys and ids unchanged.',
            $this->resolveBackendLanguageName($user),
        );
    }

    /**
     * English name of the site language a record is stored in.
     */
    public function resolveContentLanguageName(int $pageId, ?int $languageId = null): string
    {
        return $this->languageName($this->resolveContentIsoCode($pageId, $languageId));
    }

    /**
     * Prompt rule for values that end up in the database instead of the chat.
     */
    public function contentLanguageInstruction(int $pageId, ?int $languageId = null): string
    {
        return sprintf(
            'Write generated content that will be stored on the record in %s.',
            $this->resolveContentLanguageName($pageId, $languageId),
        );
    }

    public function resolveContentIsoCode(int $pageId, ?int $languageId = null): string
    {
        if ($pageId <= 0) {
            return self::FALLBACK_ISO;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $language = $languageId === null
                ? $site->getDefaultLanguage()
                : $site->getLanguageById($languageId);
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return self::FALLBACK_ISO;
        }

        // getHreflang() is a plain string on v12–v14, unlike getLocale().
        return $this->normalizeIsoCode($language->getHreflang());
    }

    private function normalizeIsoCode(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'default') {
            return self::FALLBACK_ISO;
        }

        $primary = \Locale::getPrimaryLanguage($raw);

        return is_string($primary) && $primary !== '' ? strtolower($primary) : self::FALLBACK_ISO;
    }

    private function languageName(string $isoCode): string
    {
        $name = \Locale::getDisplayLanguage($isoCode, 'en');

        // Unknown codes are echoed back by intl — never put a raw code into a prompt.
        if (!is_string($name) || $name === '' || strcasecmp($name, $isoCode) === 0) {
            return self::FALLBACK_NAME;
        }

        return $name;
    }
}
