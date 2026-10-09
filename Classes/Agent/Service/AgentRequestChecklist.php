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
 * Server-side checklist derived from the editor's message — source of truth for
 * "is anything still left?" so Progress / NL "done" cannot lie when the model
 * skips a step or marks plan items completed early.
 *
 * v1 covers numbered / labelled multi content-element requests (Text & Media,
 * Text, Bullets, Tables, Images) plus an optional attach-image step.
 *
 * @internal
 */
final class AgentRequestChecklist
{
    /**
     * @return list<array{kind: string, cType?: string, title: string, status: string}>
     */
    public static function parse(string $message): array
    {
        $message = trim($message);
        // "[The editor confirmed "Create …" …]" reports a result; it asks for nothing.
        if ($message === '' || str_starts_with($message, '[The editor ')) {
            return [];
        }

        $segments = self::requestSegments($message);
        // "Rename the header of element 5" changes an existing record; the word "header" must not turn it
        // into a request to create a header element that then stays open forever.
        if (count($segments) === 1 && self::isChangeOfExistingRecord($message)) {
            return [];
        }
        // A numbered / bulleted list spells out what to create; only a free-text single request can
        // be a pure read ("list the headers on this page").
        if (count($segments) === 1 && self::isReadOnlyRequest($message)) {
            return [];
        }
        $creates = [];
        if (count($segments) === 1) {
            foreach (self::detectCTypesInOrder($segments[0]) as $cType) {
                $creates[] = [
                    'kind' => 'create',
                    'cType' => $cType,
                    'title' => self::titleForCreate($cType, $segments[0]),
                    'status' => 'pending',
                ];
            }
        } else {
            foreach ($segments as $segment) {
                $cType = self::detectCType($segment);
                if ($cType === null) {
                    continue;
                }
                $creates[] = [
                    'kind' => 'create',
                    'cType' => $cType,
                    'title' => self::titleForCreate($cType, $segment),
                    'status' => 'pending',
                ];
            }
        }
        if ($creates === []) {
            return [];
        }

        // Deduplicate consecutive identical CTypes from noisy phrasing; keep distinct types in order.
        $unique = [];
        $seen = [];
        foreach ($creates as $step) {
            $key = (string) $step['cType'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $step;
        }

        $needsImage = self::messageNeedsImage($message, $unique);
        if ($needsImage) {
            $unique[] = [
                'kind' => 'attach_image',
                'title' => 'Attach an image to the media content element',
                'status' => 'pending',
            ];
        }

        return self::markFirstInProgress($unique);
    }

    /**
     * Reconcile parse(result) against applied tools since the latest user request.
     *
     * @param list<array<string, mixed>> $history
     * @return list<array{kind: string, cType?: string, title: string, status: string}>
     */
    public static function reconcile(array $history, string $requestOverride = ''): array
    {
        $request = trim($requestOverride) !== ''
            ? trim($requestOverride)
            : AgentPromptBuilder::latestUserRequestText($history);
        $steps = self::parse($request);
        if ($steps === []) {
            return [];
        }

        $since = self::historySinceLatestUserRequest($history, $request);
        $appliedCTypes = self::appliedCTypes($since);
        $imageAttached = self::hasSuccessfulMediaAttach($since);

        foreach ($steps as $index => $step) {
            if (($step['kind'] ?? '') === 'create') {
                $cType = (string) ($step['cType'] ?? '');
                $pos = array_search($cType, $appliedCTypes, true);
                if ($pos !== false) {
                    unset($appliedCTypes[$pos]);
                    $appliedCTypes = array_values($appliedCTypes);
                    $steps[$index]['status'] = 'completed';
                }
            } elseif (($step['kind'] ?? '') === 'attach_image') {
                $steps[$index]['status'] = $imageAttached ? 'completed' : 'pending';
            }
        }

        return self::markFirstInProgress($steps);
    }

    /**
     * @param list<array{kind: string, cType?: string, title: string, status: string}> $steps
     * @return list<array{title: string, status: string}>
     */
    public static function toPlan(array $steps): array
    {
        $plan = [];
        foreach ($steps as $step) {
            $plan[] = [
                'title' => (string) ($step['title'] ?? ''),
                'status' => (string) ($step['status'] ?? 'pending'),
            ];
        }

        return $plan;
    }

    /**
     * @param list<array{kind: string, cType?: string, title: string, status: string}> $steps
     */
    public static function hasOpen(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step['status'] ?? '') !== 'completed') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{kind: string, cType?: string, title: string, status: string}> $steps
     */
    public static function nextActionHint(array $steps): string
    {
        foreach ($steps as $step) {
            if (($step['status'] ?? '') === 'completed') {
                continue;
            }
            if (($step['kind'] ?? '') === 'attach_image') {
                return 'Next: generate or upload an image, then call file_reference_add on the Text & Media / Images element (fieldName "assets" or "image"). Do not claim the request is finished until that succeeds.';
            }
            $cType = (string) ($step['cType'] ?? '');
            if ($cType !== '') {
                return sprintf(
                    'Next: prepare a tt_content element with CType "%s" (write_table). Do not create a different CType and do not claim the request is finished.',
                    $cType,
                );
            }
        }

        return '';
    }

    /**
     * @param list<array{kind: string, cType?: string, title: string, status: string}> $steps
     */
    public static function promptBlock(array $steps): string
    {
        if ($steps === [] || !self::hasOpen($steps)) {
            return '';
        }
        $lines = [];
        foreach ($steps as $number => $step) {
            $lines[] = sprintf('%d. [%s] %s', $number + 1, $step['status'], $step['title']);
        }
        $next = self::nextActionHint($steps);

        return 'Server checklist from the editor request (authoritative — keep going until every step is completed;'
            . ' do not claim finished while any step is pending or in_progress):'
            . "\n" . implode("\n", $lines)
            . ($next !== '' ? "\n" . $next : '');
    }

    /**
     * Prefer checklist when it has open work; otherwise fall back to the model plan.
     *
     * @param list<array<string, mixed>> $history
     * @param list<array{title: string, status: string}> $modelPlan
     * @return list<array{title: string, status: string}>
     */
    public static function effectivePlan(array $history, array $modelPlan, string $requestOverride = ''): array
    {
        $checklist = self::reconcile($history, $requestOverride);
        if ($checklist !== []) {
            return self::toPlan($checklist);
        }

        return $modelPlan;
    }

    /**
     * "List the headers on this page" names a content type but asks to read, not to create —
     * it must not open a "Create Header element" step that can never be completed.
     */
    private const CREATE_VERBS = '/\b(add|create|insert|make|build|generate|write|append|draft|new|erstell\w*|f(?:ü|ue)ge?\w*|hinzu\w*|anleg\w*|neue[rsn]?)\b/u';

    /**
     * An edit of something that already exists (rename, change, delete, translate…) with no word that asks
     * for a new element.
     */
    private static function isChangeOfExistingRecord(string $message): bool
    {
        $s = mb_strtolower($message);
        if (preg_match(self::CREATE_VERBS, $s) === 1) {
            return false;
        }

        return preg_match(
            '/\b(rename|change|update|edit|set|delete|remove|translate|move|replace|fix|correct|[äa]ndere\w*|umbenenn\w*|l[öo]sch\w*|entfern\w*|[üu]bersetz\w*|verschieb\w*)\b/u',
            $s,
        ) === 1;
    }

    private static function isReadOnlyRequest(string $message): bool
    {
        $s = mb_strtolower($message);
        // A question ("Which content element types can an editor create here?") asks for information even
        // though it names an element type and the word "create".
        if (preg_match('/^\s*(?:which|what|who|where|when|why|how\s+many|how\s+much|welche\w*|was|wer|wo|wann|warum|wie\s+viele)\b/u', $s) === 1) {
            return true;
        }
        if (preg_match(self::CREATE_VERBS, $s) === 1) {
            return false;
        }

        return preg_match(
            '/\b(list|show|display|read|get|find|search|count|summari[sz]e|explain|describe|tell|what|which|who|where|when|how\s+many|zeig\w*|liste?\w*|such\w*|finde\w*|welche\w*|was|wie\s+viele|erkl(?:ä|ae)r\w*)\b/u',
            $s,
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private static function requestSegments(string $message): array
    {
        if (preg_match_all(
            '/(?:^|\n)\s*(?:\d+[\.\)]\s*|\*\s+|-\s+)(.+?)(?=(?:\n\s*(?:\d+[\.\)]\s*|\*\s+|-\s+)|\z))/us',
            $message,
            $matches,
        ) > 0) {
            return array_values(array_filter(array_map('trim', $matches[1])));
        }

        // Unnumbered: "Add a Text & Media element and a separate Text element"
        return [$message];
    }

    private static function detectCType(string $segment): ?string
    {
        $found = self::detectCTypesInOrder($segment);

        return $found[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function detectCTypesInOrder(string $text): array
    {
        $s = mb_strtolower($text);
        // "…a text element with the header X" / "…mit der Überschrift X" names a FIELD of the element, not a second
        // Header element: it must not open a step that nothing will ever complete. The value that follows
        // ("…the header QA Header Check") is dropped too, as it may contain the word "header" itself.
        $s = (string) preg_replace(
            '/\b(?:with|having|including|titled|mit|inklusive)\s+(?:(?:the|a|an|its|der|die|dem|einer|einem|dessen)\s+)?(?:headers?|headline|überschrift|ueberschrift)\b.*?(?=\s+(?:and|und)\s+(?:the|a|an|with|mit|der|die|das|ein|eine)\b|[,;.]|$)/u',
            ' ',
            $s,
        );
        $hits = [];
        $patterns = [
            'textmedia' => '/\b(text\s*&\s*media|text\s+and\s+media|textmedia)\b/u',
            'textpic' => '/\b(text\s*&\s*images|text\s+and\s+images|textpic)\b/u',
            'images' => '/\b(images|bilder)\b/u',
            'bullets' => '/\b(bullets?|bullet\s*points?|liste|aufzählung|aufzaehlung)\b/u',
            'table' => '/\b(tables?|tabelle)\b/u',
            'header' => '/\b(headers?|headline|überschrift|ueberschrift)\b/u',
            'text' => '/\btext\b/u',
        ];
        foreach ($patterns as $cType => $pattern) {
            if (preg_match($pattern, $s, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $mapped = $cType === 'images' ? 'textpic' : $cType;
            if ($mapped === 'text' && preg_match('/\b(text\s*&\s*media|text\s+and\s+media|textmedia|textpic)\b/u', $s) === 1) {
                // Skip the "Text" inside "Text & Media" — only count a later bare Text if present after.
                $offset = (int) $m[0][1];
                $before = mb_substr($s, max(0, $offset - 20), 40);
                if (preg_match('/text\s*&\s*media|text\s+and\s+media|textmedia/u', $before) === 1) {
                    // find next bare "text" that is not part of textmedia
                    if (preg_match_all('/\btext\b/u', $s, $all, PREG_OFFSET_CAPTURE) > 0) {
                        foreach ($all[0] as $hit) {
                            $pos = (int) $hit[1];
                            $window = mb_substr($s, $pos, 20);
                            if (preg_match('/^text\s*&\s*media|^text\s+and\s+media|^textmedia/u', $window) === 1) {
                                continue;
                            }
                            $hits[] = ['cType' => 'text', 'pos' => $pos];
                            break;
                        }
                    }
                    continue;
                }
            }
            $hits[] = ['cType' => $mapped, 'pos' => (int) $m[0][1]];
        }
        usort($hits, static fn(array $a, array $b): int => $a['pos'] <=> $b['pos']);
        $ordered = [];
        $seen = [];
        foreach ($hits as $hit) {
            $cType = $hit['cType'];
            if (isset($seen[$cType])) {
                continue;
            }
            $seen[$cType] = true;
            $ordered[] = $cType;
        }

        return $ordered;
    }

    private static function titleForCreate(string $cType, string $segment): string
    {
        $labels = [
            'textmedia' => 'Create Text & Media element',
            'textpic' => 'Create Images / Text & Images element',
            'text' => 'Create Text element',
            'bullets' => 'Create Bullets element',
            'table' => 'Create Table element',
            'header' => 'Create Header element',
        ];
        $base = $labels[$cType] ?? ('Create ' . $cType . ' element');
        if (preg_match('/\*\*(.+?)\*\*/u', $segment, $m) === 1) {
            return $base . ' (' . trim($m[1]) . ')';
        }

        return $base;
    }

    /**
     * @param list<array{kind: string, cType?: string, title: string, status: string}> $creates
     */
    private static function messageNeedsImage(string $message, array $creates): bool
    {
        $hasMediaCreate = false;
        foreach ($creates as $step) {
            if (in_array((string) ($step['cType'] ?? ''), ['textmedia', 'textpic', 'image'], true)) {
                $hasMediaCreate = true;
                break;
            }
        }
        if (!$hasMediaCreate) {
            return false;
        }

        return AgentPromptBuilder::requestMentionsImage($message)
            || preg_match('/\b(images?|bilder?)\b/ui', $message) === 1;
    }

    /**
     * @param list<array{kind: string, cType?: string, title: string, status: string}> $steps
     * @return list<array{kind: string, cType?: string, title: string, status: string}>
     */
    private static function markFirstInProgress(array $steps): array
    {
        $found = false;
        foreach ($steps as $index => $step) {
            if (($step['status'] ?? '') === 'completed') {
                continue;
            }
            $steps[$index]['status'] = $found ? 'pending' : 'in_progress';
            $found = true;
        }

        return $steps;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array<string, mixed>>
     */
    public static function historySinceLatestUserRequest(array $history, string $requestText = ''): array
    {
        $start = 0;
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $meta = is_array($history[$i]['meta'] ?? null) ? $history[$i]['meta'] : [];
            if (($meta['type'] ?? '') === 'continuation') {
                continue;
            }
            $content = trim((string) ($history[$i]['content'] ?? ''));
            if ($requestText !== '' && $content !== '' && !str_contains($content, mb_substr($requestText, 0, 40))) {
                // Keep scanning for the message that matches this request when provided.
                if ($content !== trim($requestText) && !str_starts_with($content, mb_substr(trim($requestText), 0, 80))) {
                    continue;
                }
            }
            $start = $i + 1;
            break;
        }

        return array_slice($history, $start);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<string>
     */
    private static function appliedCTypes(array $history): array
    {
        $types = [];
        foreach ($history as $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($entry['role'] ?? '') !== 'assistant' || ($meta['type'] ?? '') !== 'readback_result') {
                continue;
            }
            foreach (is_array($meta['readback'] ?? null) ? $meta['readback'] : [] as $row) {
                if (!is_array($row) || ($row['table'] ?? '') !== 'tt_content') {
                    continue;
                }
                $values = is_array($row['values'] ?? null) ? $row['values'] : [];
                $cType = strtolower(trim((string) ($values['CType'] ?? $values['ctype'] ?? '')));
                if ($cType !== '') {
                    $types[] = $cType;
                }
            }
        }

        return $types;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private static function hasSuccessfulMediaAttach(array $history): bool
    {
        foreach ($history as $entry) {
            $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            if (($entry['role'] ?? '') !== 'assistant' || ($meta['type'] ?? '') !== 'tool_result') {
                continue;
            }
            if (($meta['success'] ?? true) === false || (string) ($meta['tool'] ?? '') !== 'file_reference_add') {
                continue;
            }
            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            if ((string) ($details['table'] ?? '') === 'tt_content' && (int) ($details['uid'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
