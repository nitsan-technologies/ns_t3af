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

use NITSAN\NsT3AF\Mcp\Service\Backend\McpPlaygroundService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Resolves an explicit page target from NL agent messages (e.g. "Get Page 3").
 *
 * @internal
 */
final readonly class AgentTargetPageResolver
{
    public function __construct(
        private McpPlaygroundService $playgroundService,
    ) {}

    public function resolveFromMessage(
        string $message,
        int $fallbackPageId,
        BackendUserAuthentication $user,
    ): int {
        $reference = $this->extractPageReference($message);
        if ($reference === null) {
            return $fallbackPageId;
        }

        if (preg_match('/^(?:page\s+)?(\d+)$/i', $reference, $uidMatch) === 1) {
            $uid = (int) $uidMatch[1];
            if ($uid > 0 && $this->userCanReadPage($uid, $user)) {
                return $uid;
            }
        }

        $uid = $this->searchPageUidByTitle($reference, $user);
        if ($uid > 0) {
            return $uid;
        }

        return $fallbackPageId;
    }

    public function extractPageReference(string $message): ?string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/@pages:(\d+)/i', $trimmed, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\bpage\s+(?:uid|id|#)\s*(\d+)\b/i', $trimmed, $matches)) {
            return $matches[1];
        }

        if (preg_match(
            '/\b(?:get|fetch|read|show|open|inspect|use|from)\s+(?:the\s+)?(.+?)(?:\s*[.,]|\s+translate|\s+then|\s+and|\s+optimi)/i',
            $trimmed,
            $matches,
        )) {
            $candidate = trim($matches[1]);
            if (preg_match('/^page\b/i', $candidate)) {
                return $candidate;
            }
        }

        if (preg_match(
            '/\b(?:on|for)\s+(?:the\s+)?(page\s+[^.,]+?)(?:\s*[.,]|\s+translate|\s+then|\s+optimi|$)/i',
            $trimmed,
            $matches,
        )) {
            return trim($matches[1]);
        }

        return self::namedPageTitle($trimmed);
    }

    /**
     * A page the editor named ("the Contact page", "page called Contact").
     * "this page" and "Generate SEO for this page" are not a name.
     */
    public static function namedPageTitle(string $message): ?string
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        if (preg_match(
            '/\b(?:page|seite)\s+(?:called|named|titled|namens|genannt|heisst|heißt)\s+["“]?([^"“”\n.]{1,80})/iu',
            $message,
            $called,
        ) === 1) {
            $name = self::cleanTitle((string) $called[1]);
            if ($name !== null) {
                return $name;
            }
        }

        if (preg_match_all(
            '/\b((?:[\p{L}\p{N}][\p{L}\p{N}\'’.\-]*\s+){1,6})pages?\b/iu',
            $message,
            $before,
            PREG_SET_ORDER,
        ) < 1) {
            return null;
        }

        foreach ($before as $match) {
            $name = self::titleBeforePage((string) $match[1]);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * "Create a page called Contact" is a new page, not a lookup of an existing one.
     */
    public static function isCreatePageRequest(string $message): bool
    {
        return preg_match('/\b(create|new|anleg\w*|neue[rnms]?|hinzufüg\w*|hinzufueg\w*)\b/iu', $message) === 1
            && preg_match('/\b(page|seite)\b/iu', $message) === 1;
    }

    /**
     * The page the editor picked from a missing-page question ("the first one", or a button label).
     *
     * @param list<array<string, mixed>> $history
     */
    public static function pageIdFromChoice(string $message, array $history): int
    {
        $options = [];
        $missingPage = false;
        for ($i = count($history) - 1; $i >= 0; --$i) {
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($meta['type'] ?? '') !== 'clarification') {
                continue;
            }
            $raw = is_array($meta['options'] ?? null) ? $meta['options'] : [];
            $options = array_values(array_filter($raw, 'is_string'));
            $missingPage = ($meta['missingPage'] ?? false) === true;
            break;
        }
        if ($options === []) {
            return 0;
        }

        $message = trim($message);
        foreach ($options as $option) {
            $bare = trim((string) preg_replace('/\s*\[\d+\]\s*$/', '', $option));
            if (strcasecmp($message, $option) === 0 || ($bare !== '' && strcasecmp($message, $bare) === 0)) {
                return self::uidFromLabel($option);
            }
        }
        if (!$missingPage) {
            return 0;
        }

        $index = self::ordinalIndex($message);
        if ($index === null || !isset($options[$index])) {
            return 0;
        }

        return self::uidFromLabel($options[$index]);
    }

    /**
     * Closest page titles, as "Title [uid]", for the editor to pick.
     *
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    public static function rankPageLabels(string $needle, array $records): array
    {
        $needle = mb_strtolower(trim($needle));
        $scored = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $uid = (int) ($record['uid'] ?? 0);
            $title = trim((string) ($record['title'] ?? ''));
            if ($uid <= 0 || $title === '' || self::titleMatches($title, $needle)) {
                continue;
            }
            $percent = 0.0;
            similar_text($needle, mb_strtolower($title), $percent);
            $scored[$uid] = ['label' => $title . ' [' . $uid . ']', 'score' => $percent];
        }
        uasort(
            $scored,
            static fn(array $left, array $right): int => $right['score'] <=> $left['score'],
        );

        return array_slice(array_column(array_values($scored), 'label'), 0, 5);
    }

    /**
     * A page whose title is exactly this name. A close title is offered as a choice instead.
     *
     * @return array{uid: int, title: string}|null
     */
    public function matchingPage(string $title, BackendUserAuthentication $user): ?array
    {
        foreach ($this->searchRecords($title, 10) as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            $pageTitle = trim((string) ($record['title'] ?? ''));
            if ($uid <= 0 || !self::titleMatches($pageTitle, $title) || !$this->userCanReadPage($uid, $user)) {
                continue;
            }

            return ['uid' => $uid, 'title' => $pageTitle];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function similarPageLabels(string $title, BackendUserAuthentication $user): array
    {
        $records = $this->searchRecords($title, 10);
        $stem = mb_substr(trim($title), 0, 4);
        if ($records === [] && mb_strlen($stem) >= 3 && mb_strtolower($stem) !== mb_strtolower(trim($title))) {
            $records = $this->searchRecords($stem, 20);
        }
        if ($records === []) {
            $records = $this->searchRecords(
                json_encode(['title' => ['op' => 'notNull', 'value' => '1']], JSON_THROW_ON_ERROR),
                40,
            );
        }

        $readable = [];
        foreach ($records as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            if ($uid > 0 && $this->userCanReadPage($uid, $user)) {
                $readable[] = $record;
            }
        }

        return self::rankPageLabels($title, $readable);
    }

    private function searchPageUidByTitle(string $reference, BackendUserAuthentication $user): int
    {
        $search = trim($reference);
        if ($search === '') {
            return 0;
        }

        $result = $this->playgroundService->invoke('pages_search', [
            'search' => $search,
            'limit' => 10,
        ]);
        if (!(bool) ($result['success'] ?? false)) {
            return 0;
        }

        $records = $this->normalizeRecords($result['result'] ?? null);
        $normalizedNeedle = strtolower($search);

        foreach ($records as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            if ($uid <= 0 || !$this->userCanReadPage($uid, $user)) {
                continue;
            }

            $title = strtolower(trim((string) ($record['title'] ?? '')));
            if ($title === $normalizedNeedle) {
                return $uid;
            }
        }

        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchRecords(string $search, int $limit): array
    {
        $result = $this->playgroundService->invoke('pages_search', [
            'search' => $search,
            'limit' => $limit,
        ]);
        if (!(bool) ($result['success'] ?? false)) {
            return [];
        }

        return $this->normalizeRecords($result['result'] ?? null);
    }

    private static function titleMatches(string $title, string $needle): bool
    {
        $title = mb_strtolower(trim($title));
        $needle = mb_strtolower(trim($needle));

        return $title !== '' && $needle !== '' && $title === $needle;
    }

    private static function titleBeforePage(string $raw): ?string
    {
        $words = preg_split('/\s+/u', trim($raw)) ?: [];
        $title = [];
        for ($i = count($words) - 1; $i >= 0; --$i) {
            $word = trim((string) $words[$i], " \t\"“”'.,");
            if ($word === '' || self::isTitleStopWord($word)) {
                break;
            }
            array_unshift($title, $word);
            if (count($title) === 3) {
                break;
            }
        }

        return $title === [] ? null : implode(' ', $title);
    }

    private static function cleanTitle(string $title): ?string
    {
        $title = trim($title, " \t\"“”'.");
        if ($title === '' || self::isTitleStopWord($title)) {
            return null;
        }

        return $title;
    }

    private static function isTitleStopWord(string $word): bool
    {
        return in_array(mb_strtolower($word), [
            'the', 'a', 'an', 'this', 'that', 'these', 'those', 'current', 'same', 'whole', 'entire',
            'new', 'my', 'our', 'your', 'for', 'on', 'to', 'of', 'in', 'up', 'and', 'or', 'its', 'their',
            'make', 'show', 'get', 'first', 'last', 'next', 'previous', 'other',
            'die', 'der', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'einen', 'einem',
            'dieser', 'diese', 'dieses', 'diesen', 'aktuelle', 'aktuellen', 'neuer', 'neue', 'neuen',
            'erste', 'letzter', 'letzte', 'letzten', 'nächste', 'naechste',
        ], true);
    }

    private static function uidFromLabel(string $label): int
    {
        if (preg_match('/\[(\d+)\]\s*$/', $label, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    private static function ordinalIndex(string $message): ?int
    {
        $message = mb_strtolower(trim($message));
        if (preg_match('/\b(?:first|1st|erste[rnms]?|erster)\b/u', $message) === 1) {
            return 0;
        }
        if (preg_match('/\b(?:second|2nd|zweite[rnms]?)\b/u', $message) === 1) {
            return 1;
        }
        if (preg_match('/\b(?:third|3rd|dritte[rnms]?)\b/u', $message) === 1) {
            return 2;
        }
        if (preg_match('/\b(?:fourth|4th|vierte[rnms]?)\b/u', $message) === 1) {
            return 3;
        }
        if (preg_match('/\b(?:fifth|5th|fünfte[rnms]?|fuenfte[rnms]?)\b/u', $message) === 1) {
            return 4;
        }
        if (preg_match('/^(?:#|option\s+|nummer\s+)?([1-5])\b/u', $message, $match) === 1) {
            return (int) $match[1] - 1;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRecords(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return [];
        }
        if (isset($payload['records']) && is_array($payload['records'])) {
            /** @var list<array<string, mixed>> $records */
            $records = array_values(array_filter($payload['records'], 'is_array'));

            return $records;
        }

        return [];
    }

    private function userCanReadPage(int $pageId, BackendUserAuthentication $user): bool
    {
        return BackendUtility::readPageAccess($pageId, $user->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }
}
