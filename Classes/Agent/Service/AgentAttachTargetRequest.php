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

/**
 * What an "add this image to the news X" / "to content element 82" request points at, read from the text.
 * The record itself is looked up by {@see AgentAttachTargetResolver}.
 *
 * @internal
 */
final class AgentAttachTargetRequest
{
    /** Words that follow "news" / "content element" without being a title. */
    private const NOT_A_TITLE = [
        'article', 'articles', 'record', 'records', 'item', 'items', 'entry', 'entries', 'page', 'here',
        'on', 'in', 'this', 'that', 'the', 'a', 'an', 'it', 'artikel', 'nachricht', 'seite', 'hier', 'dieser', 'diese',
    ];

    /**
     * @return array{kind: 'news'|'content', uid?: int, title?: string}|null
     */
    public static function parse(string $request): ?array
    {
        $request = trim($request);
        if ($request === '' || !AgentRequestedFiles::asksToAttachOrPlaceFile($request)) {
            return null;
        }
        // A new element is created first and gets the image after Apply (a later step).
        if (self::asksForNewContentElementAttach($request)) {
            return null;
        }

        if (preg_match('/\b(?:content\s+element|inhaltselement|element|ce)\s+(?:uid\s+)?#?(\d{1,10})\b/iu', $request, $match) === 1) {
            return ['kind' => 'content', 'uid' => (int) $match[1]];
        }
        if (preg_match('/\b(?:content\s+element|inhaltselement)\s+(?<title>.+)$/iu', $request, $match) === 1) {
            $title = self::cleanTitle($match['title']);
            if ($title !== '') {
                return ['kind' => 'content', 'title' => $title];
            }
        }
        if (preg_match('/\bnews(?:\s+(?:article|record|item|entry))?\s+(?:uid\s+)?#?(\d{1,10})\b/iu', $request, $match) === 1) {
            return ['kind' => 'news', 'uid' => (int) $match[1]];
        }
        if (preg_match('/\bnews(?:\s+(?:article|record|item|entry))?\s+(?<title>.+)$/iu', $request, $match) === 1) {
            $title = self::cleanTitle($match['title']);
            if ($title !== '') {
                return ['kind' => 'news', 'title' => $title];
            }
        }

        return null;
    }

    /**
     * sys_file uids of files the editor attached with the composer (@file), as read by file_get_info.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<int>
     */
    public static function uploadedFileUids(array $messages): array
    {
        $uids = [];
        foreach ($messages as $message) {
            $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            if (
                ($meta['triggeredByAttachment'] ?? false) === true
                && ($meta['tool'] ?? '') === 'file_get_info'
                && ($meta['success'] ?? true) !== false
                && (int) ($details['uid'] ?? 0) > 0
            ) {
                $uids[(int) $details['uid']] = true;
            }
        }

        return array_keys($uids);
    }

    /**
     * The one earlier upload that is still waiting for a place ("upload first, then say where it goes"),
     * or null when there is none or more than one, so the model asks which.
     *
     * @param list<array<string, mixed>> $history
     * @param array<int, true> $attached uids a file_reference_add already covered
     */
    public static function onlyWaitingUpload(array $history, array $attached): ?int
    {
        $waiting = array_values(array_filter(
            self::uploadedFileUids($history),
            static fn(int $uid): bool => !isset($attached[$uid]),
        ));

        return count($waiting) === 1 ? $waiting[0] : null;
    }

    /**
     * "Add this image to a new content element on this page" — create first, attach after Apply.
     */
    public static function asksForNewContentElementAttach(string $request): bool
    {
        // "Create a new Text & Media element for the image" / "A new Text & Media element (… attach …)"
        $newElement = preg_match(
            '/\b(?:new|another|neue[nrsm]?|weitere[nrsm]?)\s+(?:[\w&]+\s+){0,4}(?:content\s+)?(?:element|inhaltselement|text\s*&\s*media|textmedia|textpic)\b/iu',
            $request,
        ) === 1;
        if (!$newElement) {
            return false;
        }

        // Clarification choices often say "for the image" / "and attach the image" without a place verb.
        // Do not treat a bare "Add a new … element about a picture …" as an attach request (add ≠ attach).
        return AgentRequestedFiles::asksToAttachOrPlaceFile($request)
            || preg_match('/\b(?:attach|put|place|insert|anh[äa]ng\w*|hinzuf[üu]g\w*)\b[^.]{0,40}\b(?:image|picture|photo|file|bild|foto|datei)\b/iu', $request) === 1
            || preg_match('/\b(?:for|with|und)\s+(?:the\s+|das\s+|die\s+|den\s+)?(?:image|picture|photo|file|bild|foto|datei)\b/iu', $request) === 1;
    }

    private static function cleanTitle(string $raw): string
    {
        $title = trim($raw);
        $title = (string) preg_replace('/^[\s"\'\x{00AB}\x{00BB}\x{2018}\x{2019}\x{201A}\x{201B}\x{201C}\x{201D}\x{201E}\x{2039}\x{203A}`]+/u', '', $title);
        $title = (string) preg_replace('/[\s"\'\x{00AB}\x{00BB}\x{2018}\x{2019}\x{201A}\x{201B}\x{201C}\x{201D}\x{201E}\x{2039}\x{203A}`.!?]+$/u', '', $title);
        if ($title === '' || in_array(mb_strtolower($title), self::NOT_A_TITLE, true)) {
            return '';
        }

        return $title;
    }
}
